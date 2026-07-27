<?php

declare(strict_types=1);

namespace App\Command;

use App\Query\GetSendersAwaitingReview;
use App\Repository\TeamRepository;
use App\Results\DomainSendersAwaitingReview;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\SenderReviewMateriality;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Emails a team once when unreviewed senders have piled up enough to matter.
 *
 * WHY a cron and not the report-processing path: ingest sees one report at a
 * time and has no idea whether the resulting `known_sender` row is the fifth
 * unreviewed sender this month or the first. Deciding "this is now worth an
 * email" needs the whole picture, which only exists between reports.
 *
 * Threshold: {@see SenderReviewMateriality} — volume, not count. Ten senders
 * that delivered one message each never trigger this; one sender carrying real
 * traffic that nobody has vouched for does.
 *
 * De-dup: an {@see AlertType::NewUnknownSender} alert stamped
 * `data.notification = senders_awaiting_review` is written whenever the email
 * goes out, and a 30-day COUNT(*) over that marker gates the next send — the
 * same shape as {@see \App\MessageHandler\RecommendPolicyUpgrade}. The marker
 * makes it distinguishable from the per-report new-sender alerts, which share
 * the type and would otherwise suppress this forever. The alert is also the
 * in-app half of the notification, so the dashboard and the inbox say the same
 * thing.
 *
 * Idempotent: running it twice in a day sends nothing the second time.
 */
#[AsCommand(
    name: 'sendvery:senders:review-reminder',
    description: 'Email teams whose unreviewed senders have crossed a materiality threshold (deduped to once per 30 days).',
)]
final class NotifySendersAwaitingReviewCommand extends Command
{
    /**
     * Marker written into `alert.data` so this notification's own history is
     * distinguishable from the per-report new-sender alerts that share
     * {@see AlertType::NewUnknownSender}.
     */
    public const string NOTIFICATION_KEY = 'senders_awaiting_review';

    private const int DEDUPE_DAYS = 30;

    public function __construct(
        private readonly GetSendersAwaitingReview $sendersAwaitingReview,
        private readonly TeamRepository $teamRepository,
        private readonly AlertEngine $alertEngine,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $database,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Restrict to a single team UUID — for previewing the email against one team.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be sent and send nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $dashboardUrl = $this->urlGenerator->generate('dashboard_overview', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $io->comment(sprintf('Links will point at %s', $dashboardUrl));

        $notified = 0;

        foreach ($this->targetTeamIds($input) as $teamId) {
            $domains = array_values(array_filter(
                $this->sendersAwaitingReview->forTeam($teamId),
                static fn (DomainSendersAwaitingReview $domain): bool => $domain->isMaterial(),
            ));

            if ([] === $domains) {
                continue;
            }

            $senderCount = array_sum(array_map(
                static fn (DomainSendersAwaitingReview $domain): int => $domain->needsReviewCount,
                $domains,
            ));

            if ($this->alreadyNotified($teamId)) {
                $io->comment(sprintf('Team %s: %d sender(s) awaiting review, already notified within %d days.', $teamId, $senderCount, self::DEDUPE_DAYS));

                continue;
            }

            $recipients = $this->recipients($teamId);

            if ([] === $recipients) {
                $io->comment(sprintf('Team %s: %d sender(s) awaiting review, but nobody has email alerts enabled.', $teamId, $senderCount));

                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf(
                    'Would email %s about %d sender(s) across %d domain(s).',
                    implode(', ', $recipients),
                    $senderCount,
                    count($domains),
                ));
                ++$notified;

                continue;
            }

            $this->send($recipients, $senderCount, $domains, $dashboardUrl);
            $this->recordNotification($teamId, $senderCount, $domains);
            ++$notified;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        if (0 === $notified) {
            $io->info('No team has unreviewed senders material enough to email about.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%s %d team(s) about senders awaiting review.', $dryRun ? 'Would notify' : 'Notified', $notified));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targetTeamIds(InputInterface $input): array
    {
        $team = $input->getOption('team');

        if (is_string($team) && '' !== $team) {
            return [$team];
        }

        return $this->sendersAwaitingReview->teamIdsWithUnreviewedSenders();
    }

    private function alreadyNotified(string $teamId): bool
    {
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
             WHERE team_id = :teamId
               AND type = :type
               AND data->>\'notification\' = :notification
               AND created_at > NOW() - INTERVAL \''.self::DEDUPE_DAYS.' days\'',
            [
                'teamId' => $teamId,
                'type' => AlertType::NewUnknownSender->value,
                'notification' => self::NOTIFICATION_KEY,
            ],
        )->fetchOne();

        return (int) $count > 0;
    }

    /**
     * Honours the same per-user `email_alerts_enabled` switch that
     * {@see \App\MessageHandler\SendAlertEmailNotification} respects — a user who
     * turned alert email off must not be reached through a side door.
     *
     * @return list<string>
     */
    private function recipients(string $teamId): array
    {
        /** @var list<string> $emails */
        $emails = $this->database->executeQuery(
            'SELECT u.email
             FROM "user" u
             JOIN team_membership tm ON tm.user_id = u.id
             WHERE tm.team_id = :teamId
               AND u.email_alerts_enabled = true',
            ['teamId' => $teamId],
        )->fetchFirstColumn();

        return $emails;
    }

    /**
     * @param list<string>                      $recipients
     * @param list<DomainSendersAwaitingReview> $domains
     */
    private function send(array $recipients, int $senderCount, array $domains, string $dashboardUrl): void
    {
        $html = $this->twig->render('emails/senders_awaiting_review.html.twig', [
            'senderCount' => $senderCount,
            'domains' => $domains,
            'dashboardUrl' => $dashboardUrl,
        ]);

        $subject = sprintf('%d sender%s waiting for your review', $senderCount, 1 === $senderCount ? '' : 's');

        foreach ($recipients as $recipient) {
            $this->mailer->send(
                (new Email())
                    ->to($recipient)
                    ->subject($subject)
                    ->html($html)
                    ->text($this->plainText($senderCount, $domains, $dashboardUrl)),
            );
        }
    }

    /**
     * @param list<DomainSendersAwaitingReview> $domains
     */
    private function plainText(int $senderCount, array $domains, string $dashboardUrl): string
    {
        $lines = [];
        $lines[] = sprintf('%d sender%s waiting for your review', $senderCount, 1 === $senderCount ? '' : 's');
        $lines[] = str_repeat('=', 50);
        $lines[] = '';
        $lines[] = 'These servers have sent mail as your domains and nobody has said yet whether they are yours.';
        $lines[] = 'Marking them changes no DNS and blocks no mail.';
        $lines[] = '';

        foreach ($domains as $domain) {
            $lines[] = sprintf(
                '%s — %d sender(s), %s message(s)',
                $domain->domainName,
                $domain->needsReviewCount,
                number_format($domain->needsReviewMessages),
            );

            foreach ($domain->topSenderNames as $name) {
                $lines[] = '    '.$name;
            }

            if ($domain->hasMoreThanNamed()) {
                $lines[] = sprintf('    +%d more', $domain->unnamedCount());
            }

            $lines[] = '    Review: '.$this->urlGenerator->generate(
                'dashboard_sender_inventory',
                ['domainId' => $domain->domainId, 'filter' => 'needs_review'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $lines[] = '';
        }

        $lines[] = 'How to tell if a sender is really yours:';
        $lines[] = '  1. You recognise the organisation.';
        $lines[] = '  2. DKIM passes close to 100% — a spoofer cannot sign with your key.';
        $lines[] = '  3. The volume matches mail you actually send.';
        $lines[] = '';
        $lines[] = 'Dashboard: '.$dashboardUrl;
        $lines[] = '';
        $lines[] = '— Sendvery';

        return implode("\n", $lines);
    }

    /**
     * @param list<DomainSendersAwaitingReview> $domains
     */
    private function recordNotification(string $teamId, int $senderCount, array $domains): void
    {
        $this->alertEngine->createAlert(
            team: $this->teamRepository->get(Uuid::fromString($teamId)),
            // Team-scoped on purpose: the notification spans domains, and
            // pinning it to one of them would misreport the others.
            monitoredDomain: null,
            type: AlertType::NewUnknownSender,
            severity: AlertSeverity::Warning,
            title: sprintf('%d sender%s waiting for your review', $senderCount, 1 === $senderCount ? '' : 's'),
            message: sprintf(
                'Servers are sending mail as %s and nobody has decided whether they are yours. Review them so the senders you do not recognise stand out.',
                implode(', ', array_map(
                    static fn (DomainSendersAwaitingReview $domain): string => $domain->domainName,
                    $domains,
                )),
            ),
            data: [
                'notification' => self::NOTIFICATION_KEY,
                'sender_count' => $senderCount,
                'domains' => array_map(
                    static fn (DomainSendersAwaitingReview $domain): string => $domain->domainName,
                    $domains,
                ),
            ],
        );
    }
}

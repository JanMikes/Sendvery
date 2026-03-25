<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendWeeklyDigest;
use App\Repository\TeamRepository;
use App\Services\Digest\WeeklyDigestGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class SendWeeklyDigestHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
        private WeeklyDigestGenerator $digestGenerator,
        private MailerInterface $mailer,
        private Environment $twig,
        private Connection $database,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(SendWeeklyDigest $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $digestData = $this->digestGenerator->generate($team);

        $recipients = $this->getDigestRecipients($message->teamId->toString());

        if ([] === $recipients) {
            return;
        }

        $dashboardUrl = $this->urlGenerator->generate(
            'dashboard_overview',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $dateRange = sprintf(
            '%s — %s',
            $digestData->periodStart->format('M j'),
            $digestData->periodEnd->format('M j, Y'),
        );

        $subject = sprintf('Sendvery Weekly Report — %s — %s', $digestData->teamName, $dateRange);

        $html = $this->twig->render('emails/weekly_digest.html.twig', [
            'digest' => $digestData,
            'dashboardUrl' => $dashboardUrl,
            'dateRange' => $dateRange,
        ]);

        $plainText = $this->renderPlainText($digestData, $dashboardUrl, $dateRange);

        foreach ($recipients as $recipientEmail) {
            $email = (new Email())
                ->to($recipientEmail)
                ->subject($subject)
                ->html($html)
                ->text($plainText);

            $this->mailer->send($email);
        }
    }

    /**
     * @return array<string>
     */
    private function getDigestRecipients(string $teamId): array
    {
        return $this->database->executeQuery(
            'SELECT u.email
             FROM "user" u
             JOIN team_membership tm ON tm.user_id = u.id
             WHERE tm.team_id = :teamId
               AND u.email_digest_enabled = true',
            ['teamId' => $teamId],
        )->fetchFirstColumn();
    }

    private function renderPlainText(\App\Value\WeeklyDigestData $digest, string $dashboardUrl, string $dateRange): string
    {
        $lines = [];
        $lines[] = "Sendvery Weekly Report — {$digest->teamName}";
        $lines[] = $dateRange;
        $lines[] = str_repeat('=', 50);
        $lines[] = '';
        $lines[] = 'Summary:';
        $lines[] = "  Domains monitored: {$digest->totalDomains}";
        $lines[] = "  Total messages: {$digest->totalMessages}";
        $lines[] = sprintf('  Average pass rate: %.1f%%', $digest->averagePassRate);
        $lines[] = "  Alerts this week: {$digest->alertsCount}";
        $lines[] = "  DNS changes: {$digest->dnsChangesCount}";
        $lines[] = '';

        foreach ($digest->domains as $domain) {
            $lines[] = str_repeat('-', 40);
            $lines[] = $domain->domainName;
            $lines[] = "  Messages: {$domain->totalMessages}";
            $lines[] = sprintf('  Pass rate: %.1f%%', $domain->passRate);

            if (null !== $domain->passRateDelta) {
                $arrow = $domain->passRateDelta >= 0 ? '+' : '';
                $lines[] = sprintf('  Trend: %s%.1f%%', $arrow, $domain->passRateDelta);
            }

            if ([] !== $domain->newSenders) {
                $lines[] = '  New senders: '.implode(', ', $domain->newSenders);
            }

            if ([] !== $domain->alerts) {
                $lines[] = '  Alerts:';
                foreach ($domain->alerts as $alert) {
                    $lines[] = "    [{$alert['severity']}] {$alert['title']}";
                }
            }
        }

        $lines[] = '';
        $lines[] = "View full dashboard: {$dashboardUrl}";
        $lines[] = '';
        $lines[] = '— Sendvery';

        return implode("\n", $lines);
    }
}

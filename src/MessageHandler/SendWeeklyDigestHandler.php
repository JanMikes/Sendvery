<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Team;
use App\Message\SendWeeklyDigest;
use App\Repository\TeamRepository;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Result\WeeklyDigestResult;
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
        private AiInsightsService $aiService,
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

        // AI summary is additive and AI-plan-only; non-AI teams get the existing
        // digest unchanged. Computed after the recipients check so we never spend
        // an AI call for a team with no digest subscribers.
        $aiSummary = $this->aiSummary($team, $message);

        $html = $this->twig->render('emails/weekly_digest.html.twig', [
            'digest' => $digestData,
            'dashboardUrl' => $dashboardUrl,
            'dateRange' => $dateRange,
            'aiSummary' => $aiSummary,
        ]);

        $plainText = $this->renderPlainText($digestData, $dashboardUrl, $dateRange, $aiSummary);

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

    private function aiSummary(Team $team, SendWeeklyDigest $message): ?WeeklyDigestResult
    {
        // Plan-gated: only AI teams get a summary. The hasAi() guard means the
        // gated service won't refuse, so no AiNotEnabledForPlan handling is needed.
        if (!$team->getSubscriptionPlan()->hasAi()) {
            return null;
        }

        return $this->aiService->generateWeeklyDigest($message->teamId);
    }

    private function renderPlainText(\App\Value\WeeklyDigestData $digest, string $dashboardUrl, string $dateRange, ?WeeklyDigestResult $aiSummary): string
    {
        $lines = [];
        $lines[] = "Sendvery Weekly Report — {$digest->teamName}";
        $lines[] = $dateRange;
        $lines[] = str_repeat('=', 50);
        $lines[] = '';

        if (null !== $aiSummary) {
            $lines[] = $aiSummary->summaryMarkdown;
            foreach ($aiSummary->recommendations as $recommendation) {
                $lines[] = '  • '.$recommendation;
            }
            $lines[] = '';
        }

        $lines[] = 'Summary:';
        $lines[] = "  Domains monitored: {$digest->totalDomains}";
        $lines[] = "  Total messages: {$digest->totalMessages}";
        $lines[] = sprintf('  Average pass rate: %.1f%%', $digest->averagePassRate);
        $lines[] = "  Alerts this week: {$digest->alertsCount}";
        $lines[] = "  DNS changes: {$digest->dnsChangesCount}";
        $lines[] = '';

        if ([] !== $digest->currentlyBrokenDns) {
            $lines[] = 'DNS Records Still Broken:';
            foreach ($digest->currentlyBrokenDns as $item) {
                $lines[] = "  [{$item->checkType}] {$item->domainName} — last checked ".$item->checkedAt->format('M j, H:i');
                foreach ($item->issueMessages as $message) {
                    $lines[] = "    {$message}";
                }
            }
            $lines[] = '';
        }

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

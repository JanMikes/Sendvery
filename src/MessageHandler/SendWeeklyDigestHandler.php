<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Team;
use App\Message\SendWeeklyDigest;
use App\Repository\TeamRepository;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Value\WeeklyDigestData;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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

        // Absolute URLs in a CLI/worker context come from
        // framework.router.default_uri (env DEFAULT_URI) — there is no incoming
        // request to derive a host from. If those links ever come out as
        // localhost in production, that env var is the thing to fix.
        $dashboardUrl = $this->urlGenerator->generate(
            'dashboard_overview',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $alertsUrl = $this->urlGenerator->generate(
            'dashboard_alerts',
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
            'alertsUrl' => $alertsUrl,
            'dateRange' => $dateRange,
            'aiSummary' => $aiSummary,
        ]);

        $plainText = $this->renderPlainText($digestData, $dashboardUrl, $alertsUrl, $dateRange, $aiSummary);

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

        // The AI narration is additive garnish on a digest that is already
        // complete without it. A failing upstream call (expired key, rate limit,
        // provider outage) used to bubble out of the handler and abort the whole
        // send, so an AI-plan team got NO email at all — strictly worse than the
        // free-plan behaviour they are paying to improve on. Degrade to the
        // plain digest instead and leave a trail for whoever is on call.
        try {
            return $this->aiService->generateWeeklyDigest($message->teamId);
        } catch (\Throwable $exception) {
            $this->logger->error('Weekly digest AI summary failed; sending the digest without it.', [
                'teamId' => $message->teamId->toString(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function renderPlainText(WeeklyDigestData $digest, string $dashboardUrl, string $alertsUrl, string $dateRange, ?WeeklyDigestResult $aiSummary): string
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
        $lines[] = '  Average pass rate: '.(
            null === $digest->averagePassRate
                ? 'no reports yet'
                : sprintf('%.1f%%', $digest->averagePassRate)
        );
        $lines[] = "  Needs attention: {$digest->alertsCount}";

        if ($digest->resolvedAlertsCount > 0) {
            $lines[] = "  Resolved this week: {$digest->resolvedAlertsCount}";
        }

        $lines[] = "  DNS changes: {$digest->dnsChangesCount}";
        $lines[] = '';

        if ([] !== $digest->attentionAlerts) {
            $lines[] = 'Needs your attention:';
            foreach ($digest->attentionAlerts as $alert) {
                $scope = null !== $alert->domainName ? " ({$alert->domainName})" : '';
                $multiplier = $alert->occurrences > 1 ? " ×{$alert->occurrences}" : '';
                $lines[] = "  [{$alert->severity->value}] {$alert->title}{$scope}{$multiplier}";
            }

            if ($digest->hasMoreAttentionAlerts()) {
                $lines[] = sprintf(
                    '  … showing %d of %d — full list: %s',
                    count($digest->attentionAlerts),
                    $digest->attentionAlertGroups,
                    $alertsUrl,
                );
            }

            $lines[] = '';
        }

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
            $lines[] = '  Pass rate: '.(
                $domain->hasPassRateData()
                    ? sprintf('%.1f%%', (float) $domain->passRate)
                    : 'waiting for first report'
            );

            if (null !== $domain->passRateDelta) {
                $arrow = $domain->passRateDelta >= 0 ? '+' : '';
                $lines[] = sprintf('  Trend: %s%.1f%%', $arrow, $domain->passRateDelta);
            }

            if ([] !== $domain->newSenders) {
                $lines[] = sprintf(
                    '  New senders (%d): %s',
                    count($domain->newSenders),
                    implode(', ', $domain->newSenders),
                );
            }

            // Mirrors the HTML "Waiting for your review" block. Unlike the
            // new-senders line above this is real authorization state, not a
            // this-week window, so it keeps reporting until somebody decides.
            $senderReview = $domain->senderReview;

            if ($senderReview->hasAny()) {
                $named = implode(', ', $senderReview->topSenderNames);

                if ($senderReview->hasMoreThanNamed()) {
                    $named .= sprintf(' and %d more', $senderReview->unnamedCount());
                }

                $lines[] = sprintf(
                    '  Waiting for your review (%d, %d messages): %s',
                    $senderReview->needsReviewCount,
                    $senderReview->needsReviewMessages,
                    $named,
                );
            }
        }

        $lines[] = '';
        $lines[] = "View full dashboard: {$dashboardUrl}";
        $lines[] = '';
        $lines[] = '— Sendvery';

        return implode("\n", $lines);
    }
}

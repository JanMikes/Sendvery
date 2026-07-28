<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Services\Ai\Result\WeeklyDigestResult;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestSection;
use App\Value\WeeklyDigestSections;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The digest's text/plain alternative.
 *
 * Not a lesser copy of the HTML: plenty of readers see only this part, so it
 * carries the same facts, the same caveats and the same links. What it must
 * never do is carry *fewer* of them, which is why every section here is gated
 * by the shared {@see WeeklyDigestSection} registry rather than by a second set
 * of hand-written conditions, and why the parity test renders both parts of one
 * email and compares them.
 *
 * Links are generated here rather than handed in, against the same routes the
 * Twig template calls `url()` on. With no HTTP request behind a worker or cron
 * run, both resolve against `framework.router.default_uri` (env `DEFAULT_URI`).
 */
final readonly class WeeklyDigestPlainTextRenderer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function render(
        WeeklyDigestData $digest,
        WeeklyDigestSections $sections,
        string $dashboardUrl,
        string $alertsUrl,
        string $dateRange,
        ?WeeklyDigestResult $aiSummary,
    ): string {
        $lines = [];
        $lines[] = "Sendvery Weekly Report — {$digest->teamName}";
        $lines[] = $dateRange;
        $lines[] = str_repeat('=', 50);
        $lines[] = '';

        if ($sections->has(WeeklyDigestSection::AiSummary)) {
            assert(null !== $aiSummary);

            $lines[] = $aiSummary->summaryMarkdown;
            foreach ($aiSummary->recommendations as $recommendation) {
                $lines[] = '  • '.$recommendation;
            }
            $lines[] = '';
        }

        $lines[] = 'Summary:';
        $lines[] = "  Domains monitored: {$digest->totalDomains}";
        $lines[] = "  Total messages: {$digest->totalMessages}";
        $lines[] = '  Overall pass rate: '.(
            null === $digest->overallPassRate()
                ? 'no reports yet'
                : self::percentage($digest->overallPassRate())
        );
        // GROUPS, not raw alerts — the same number the HTML heading shows. Both
        // parts print one row per (domain, type) with an "×N" multiplier, so
        // counting the alerts behind those rows announces a total the reader
        // cannot find anywhere in the email. A week of twenty new-sender
        // detections on one domain said "Needs attention: 20" above a single
        // line, which is the "the email swallowed something" effect the HTML
        // side was fixed for.
        $lines[] = "  Needs attention: {$digest->attentionAlertGroups}";

        if ($sections->has(WeeklyDigestSection::ResolvedAlerts)) {
            $lines[] = "  Resolved this week: {$digest->resolvedAlertsCount}";
        }

        // Only when there were any. The HTML alternative has always hidden a
        // zero here, and "DNS changes: 0" in one part and silence in the other
        // is exactly the drift this registry exists to stop.
        if ($sections->has(WeeklyDigestSection::DnsChanges)) {
            $lines[] = "  DNS changes: {$digest->dnsChangesCount}";
        }

        $lines[] = '';

        if ($sections->has(WeeklyDigestSection::AttentionAlerts)) {
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

        if ($sections->has(WeeklyDigestSection::BrokenDns)) {
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
                    ? self::percentage((float) $domain->passRate)
                    : 'waiting for first report'
            );

            if (null !== $domain->passRateDelta) {
                $arrow = $domain->passRateDelta >= 0 ? '+' : '';
                $lines[] = '  Trend: '.$arrow.self::percentage($domain->passRateDelta);
            }

            if ([] !== $domain->newSenders) {
                $lines[] = sprintf('  New senders (%d):', count($domain->newSenders));

                // One line each, carrying what the sender is and how much it
                // sent. A bare list of names made a mail gateway that failed
                // two forwarded messages indistinguishable from a spoofer.
                // Capped at the same number as the HTML so the two alternatives
                // of one email never hide different amounts.
                foreach (array_slice($domain->newSenders, 0, WeeklyDigestGenerator::NEW_SENDERS_PER_DOMAIN_LIMIT) as $sender) {
                    $passRate = $sender->passRate();

                    $lines[] = sprintf(
                        '    %s — %s, %d message%s%s',
                        $sender->label,
                        $sender->role->label(),
                        $sender->messageCount,
                        1 === $sender->messageCount ? '' : 's',
                        null === $passRate ? '' : ', '.self::percentage($passRate).' pass',
                    );
                }

                $hidden = count($domain->newSenders) - WeeklyDigestGenerator::NEW_SENDERS_PER_DOMAIN_LIMIT;

                if ($hidden > 0) {
                    $lines[] = sprintf('    … and %d more', $hidden);
                }
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

                // The HTML has always ended this block with a "Review these
                // senders" link; the text alternative had the count and no
                // door. Naming a chore and withholding the page where it is
                // done is worse than staying quiet.
                $lines[] = '    Review these senders: '.$this->senderReviewUrl($domain->domainId);
            }
        }

        $lines[] = '';
        $lines[] = "View full dashboard: {$dashboardUrl}";
        $lines[] = '';
        $lines[] = '— Sendvery';

        return implode("\n", $lines);
    }

    /**
     * The HTML alternative formats every percentage with Twig's
     * `number_format`, which rounds a half away from zero; `sprintf('%.1f')`
     * does not, and prints 83.25 as "83.2" where the template prints "83.3".
     * Same float, same email, two numbers. Rounding here through the same
     * function removes the whole class.
     */
    private static function percentage(float $value): string
    {
        return number_format($value, 1).'%';
    }

    private function senderReviewUrl(string $domainId): string
    {
        return $this->urlGenerator->generate(
            'dashboard_sender_inventory',
            ['domainId' => $domainId, 'filter' => 'needs_review'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertSeverity;
use App\Value\SenderRole;
use App\Value\WeeklyDigestAlertItem;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
use App\Value\WeeklyDigestNewSender;
use App\Value\WeeklyDigestSenderReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestDataTest extends TestCase
{
    #[Test]
    public function carriesTheTeamsWeekForTheDigestEmail(): void
    {
        $periodStart = new \DateTimeImmutable('2026-03-18');
        $periodEnd = new \DateTimeImmutable('2026-03-25');

        $digest = $this->digest(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            domains: [$this->domain(totalMessages: 150, passedMessages: 143, passRate: 95.33)],
            alertsCount: 1,
            attentionAlertGroups: 1,
            attentionAlerts: [$this->alert('SPF changed', AlertSeverity::Warning, 1)],
            resolvedAlertsCount: 3,
            dnsChangesCount: 2,
        );

        self::assertSame('My Team', $digest->teamName);
        self::assertSame($periodStart, $digest->periodStart);
        self::assertSame($periodEnd, $digest->periodEnd);
        self::assertCount(1, $digest->domains);
        self::assertSame(1, $digest->totalDomains);
        self::assertSame(150, $digest->totalMessages);
        self::assertSame(143, $digest->totalPassedMessages);
        self::assertSame(1, $digest->alertsCount);
        self::assertSame(3, $digest->resolvedAlertsCount);
        self::assertSame(2, $digest->dnsChangesCount);
    }

    /**
     * The bug that shipped: the headline averaged the per-domain percentages, so
     * a domain that sent ten messages counted exactly as much as one that sent
     * forty-seven. It printed 97.9% for a week whose real, message-weighted rate
     * was 96.5%, in a sentence that explicitly claimed to describe all 57
     * messages.
     */
    #[Test]
    public function theHeadlinePassRateWeighsDomainsByHowMuchMailTheyActuallySent(): void
    {
        $digest = $this->digest(domains: [
            $this->domain(totalMessages: 10, passedMessages: 10, passRate: 100.0, name: 'small.example'),
            $this->domain(totalMessages: 47, passedMessages: 45, passRate: 95.74, name: 'busy.example'),
        ]);

        $overall = $digest->overallPassRate();

        self::assertNotNull($overall);
        self::assertSame(
            '96.5',
            number_format($overall, 1),
            'Fifty-five of fifty-seven messages passed, so the headline is 96.5% — not the 97.9% mean of the two domain rates.',
        );
    }

    #[Test]
    public function oneQuietDomainCannotSwingTheHeadlineAwayFromWhatMostMailDid(): void
    {
        // A domain that sent a single failing message used to drag the headline
        // down by tens of points because it was one of only a handful of terms
        // in an unweighted mean.
        $digest = $this->digest(domains: [
            $this->domain(totalMessages: 1000, passedMessages: 1000, passRate: 100.0, name: 'main.example'),
            $this->domain(totalMessages: 1, passedMessages: 0, passRate: 0.0, name: 'trickle.example'),
        ]);

        $overall = $digest->overallPassRate();

        self::assertNotNull($overall);
        self::assertGreaterThan(
            99.0,
            $overall,
            'A single failed message out of 1001 must barely move the headline.',
        );
    }

    #[Test]
    public function aTeamThatReceivedNoReportsHasNoOverallPassRateRatherThanZero(): void
    {
        $digest = $this->digest(domains: [
            $this->domain(totalMessages: 0, passedMessages: 0, passRate: null),
        ]);

        self::assertNull(
            $digest->overallPassRate(),
            'With nothing to measure the digest must report no rate at all, not 0%.',
        );
    }

    #[Test]
    public function noTruncationLinkWhenEveryAttentionAlertIsShown(): void
    {
        $digest = $this->digest(
            alertsCount: 4,
            attentionAlertGroups: 2,
            attentionAlerts: [
                $this->alert('SPF record missing', AlertSeverity::Critical, 1),
                $this->alert('3 new senders detected', AlertSeverity::Warning, 3),
            ],
        );

        self::assertFalse(
            $digest->hasMoreAttentionAlerts(),
            'The email must not offer a "see all" link when it is already showing everything.',
        );
    }

    #[Test]
    public function truncationLinkAppearsWhenAlertGroupsWereHeldBack(): void
    {
        $digest = $this->digest(
            alertsCount: 20,
            attentionAlertGroups: 11,
            attentionAlerts: [$this->alert('SPF record missing', AlertSeverity::Critical, 1)],
        );

        self::assertTrue(
            $digest->hasMoreAttentionAlerts(),
            'When groups are hidden the reader must be pointed at the full list.',
        );
    }

    /**
     * @param array<WeeklyDigestDomainData> $domains
     * @param list<WeeklyDigestAlertItem>   $attentionAlerts
     */
    private function digest(
        ?\DateTimeImmutable $periodStart = null,
        ?\DateTimeImmutable $periodEnd = null,
        array $domains = [],
        int $alertsCount = 0,
        int $attentionAlertGroups = 0,
        array $attentionAlerts = [],
        int $resolvedAlertsCount = 0,
        int $dnsChangesCount = 0,
    ): WeeklyDigestData {
        return new WeeklyDigestData(
            teamName: 'My Team',
            periodStart: $periodStart ?? new \DateTimeImmutable('2026-03-18'),
            periodEnd: $periodEnd ?? new \DateTimeImmutable('2026-03-25'),
            domains: $domains,
            totalDomains: count($domains),
            totalMessages: array_sum(array_map(static fn (WeeklyDigestDomainData $d): int => $d->totalMessages, $domains)),
            totalPassedMessages: array_sum(array_map(static fn (WeeklyDigestDomainData $d): int => $d->passedMessages, $domains)),
            alertsCount: $alertsCount,
            attentionAlertGroups: $attentionAlertGroups,
            attentionAlerts: $attentionAlerts,
            resolvedAlertsCount: $resolvedAlertsCount,
            dnsChangesCount: $dnsChangesCount,
        );
    }

    private function domain(
        int $totalMessages,
        int $passedMessages,
        ?float $passRate,
        string $name = 'example.com',
    ): WeeklyDigestDomainData {
        return new WeeklyDigestDomainData(
            domainName: $name,
            totalMessages: $totalMessages,
            passedMessages: $passedMessages,
            passRate: $passRate,
            passRateDelta: null,
            newSenders: [new WeeklyDigestNewSender('google.com', SenderRole::Esp, 10, 10)],
            domainId: '00000000-0000-0000-0000-000000000001',
            senderReview: WeeklyDigestSenderReview::none(),
        );
    }

    private function alert(string $title, AlertSeverity $severity, int $occurrences): WeeklyDigestAlertItem
    {
        return new WeeklyDigestAlertItem(
            title: $title,
            severity: $severity,
            domainName: 'example.com',
            occurrences: $occurrences,
        );
    }
}

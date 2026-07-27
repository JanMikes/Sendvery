<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertSeverity;
use App\Value\WeeklyDigestAlertItem;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
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
            domains: [new WeeklyDigestDomainData(
                domainName: 'example.com',
                totalMessages: 150,
                passRate: 95.5,
                passRateDelta: 2.3,
                newSenders: ['google.com'],
                domainId: '00000000-0000-0000-0000-000000000001',
                senderReview: WeeklyDigestSenderReview::none(),
            )],
            averagePassRate: 95.5,
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
        self::assertSame(95.5, $digest->averagePassRate);
        self::assertSame(1, $digest->alertsCount);
        self::assertSame(3, $digest->resolvedAlertsCount);
        self::assertSame(2, $digest->dnsChangesCount);
    }

    #[Test]
    public function aTeamThatReceivedNoReportsHasNoAveragePassRateRatherThanZero(): void
    {
        $digest = $this->digest(averagePassRate: null);

        self::assertNull(
            $digest->averagePassRate,
            'With nothing to measure the digest must report no average, not 0%.',
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
        ?float $averagePassRate = 95.5,
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
            averagePassRate: $averagePassRate,
            alertsCount: $alertsCount,
            attentionAlertGroups: $attentionAlertGroups,
            attentionAlerts: $attentionAlerts,
            resolvedAlertsCount: $resolvedAlertsCount,
            dnsChangesCount: $dnsChangesCount,
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

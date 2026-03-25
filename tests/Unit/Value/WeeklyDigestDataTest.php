<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestDataTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $periodStart = new \DateTimeImmutable('2026-03-18');
        $periodEnd = new \DateTimeImmutable('2026-03-25');

        $domain = new WeeklyDigestDomainData(
            domainName: 'example.com',
            totalMessages: 150,
            passRate: 95.5,
            passRateDelta: 2.3,
            newSenders: ['google.com'],
            alerts: [['title' => 'SPF changed', 'severity' => 'warning']],
        );

        $digest = new WeeklyDigestData(
            teamName: 'My Team',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            domains: [$domain],
            totalDomains: 1,
            totalMessages: 150,
            averagePassRate: 95.5,
            alertsCount: 1,
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
        self::assertSame(2, $digest->dnsChangesCount);
    }
}

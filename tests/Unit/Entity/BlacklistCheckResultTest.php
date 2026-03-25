<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BlacklistCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BlacklistCheckResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();

        $id = Uuid::uuid7();
        $checkedAt = new \DateTimeImmutable('2026-03-25');
        $results = [
            'zen.spamhaus.org' => ['listed' => true, 'reason' => 'Spam source'],
            'b.barracudacentral.org' => ['listed' => false, 'reason' => null],
        ];

        $checkResult = new BlacklistCheckResult(
            id: $id,
            monitoredDomain: $domain,
            ipAddress: '1.2.3.4',
            checkedAt: $checkedAt,
            results: $results,
            isListed: true,
        );

        self::assertSame($id, $checkResult->id);
        self::assertSame($domain, $checkResult->monitoredDomain);
        self::assertSame('1.2.3.4', $checkResult->ipAddress);
        self::assertSame($checkedAt, $checkResult->checkedAt);
        self::assertSame($results, $checkResult->results);
        self::assertTrue($checkResult->isListed);
    }

    #[Test]
    public function notListedResult(): void
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'clean.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();

        $checkResult = new BlacklistCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            ipAddress: '5.6.7.8',
            checkedAt: new \DateTimeImmutable(),
            results: ['zen.spamhaus.org' => ['listed' => false, 'reason' => null]],
            isListed: false,
        );

        self::assertFalse($checkResult->isListed);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DnsCheckCompleted;
use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DnsCheckResultTest extends TestCase
{
    private function createDomain(): MonitoredDomain
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test',
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

        return $domain;
    }

    #[Test]
    public function constructorSetsAllFields(): void
    {
        $id = Uuid::uuid7();
        $domain = $this->createDomain();
        $checkedAt = new \DateTimeImmutable('2026-03-25 03:00:00');
        $issues = [['severity' => 'warning', 'message' => 'Test issue']];
        $details = ['lookup_count' => 3];

        $result = new DnsCheckResult(
            id: $id,
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: $checkedAt,
            rawRecord: 'v=spf1 ~all',
            isValid: true,
            issues: $issues,
            details: $details,
            previousRawRecord: 'v=spf1 -all',
            hasChanged: true,
        );

        self::assertSame($id, $result->id);
        self::assertSame($domain, $result->monitoredDomain);
        self::assertSame(DnsCheckType::Spf, $result->type);
        self::assertSame($checkedAt, $result->checkedAt);
        self::assertSame('v=spf1 ~all', $result->rawRecord);
        self::assertTrue($result->isValid);
        self::assertSame($issues, $result->issues);
        self::assertSame($details, $result->details);
        self::assertSame('v=spf1 -all', $result->previousRawRecord);
        self::assertTrue($result->hasChanged);
    }

    #[Test]
    public function recordsDnsCheckCompletedEvent(): void
    {
        $domain = $this->createDomain();

        $result = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: 'v=DMARC1; p=reject',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: 'v=DMARC1; p=none',
            hasChanged: true,
        );

        $events = $result->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(DnsCheckCompleted::class, $events[0]);
        self::assertSame($domain->id, $events[0]->domainId);
        self::assertSame(DnsCheckType::Dmarc, $events[0]->type);
        self::assertTrue($events[0]->hasChanged);
        self::assertTrue($events[0]->isValid);
    }

    #[Test]
    public function nullableFieldsAcceptNull(): void
    {
        $domain = $this->createDomain();

        $result = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        );

        self::assertNull($result->rawRecord);
        self::assertNull($result->previousRawRecord);
        self::assertFalse($result->hasChanged);
    }
}

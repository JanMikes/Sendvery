<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DomainAdded;
use App\Value\DmarcPolicy;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MonitoredDomainTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team',
            slug: 'test-team',
            createdAt: new \DateTimeImmutable(),
        );
        $createdAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'example.com',
            createdAt: $createdAt,
        );

        self::assertSame($id, $domain->id);
        self::assertSame($team, $domain->team);
        self::assertSame('example.com', $domain->domain);
        self::assertSame($createdAt, $domain->createdAt);
        self::assertNull($domain->dmarcPolicy);
        self::assertFalse($domain->isVerified);
    }

    public function testConstructorWithOptionalFields(): void
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'test.com',
            createdAt: new \DateTimeImmutable(),
            dmarcPolicy: DmarcPolicy::Reject,
            isVerified: true,
        );

        self::assertSame(DmarcPolicy::Reject, $domain->dmarcPolicy);
        self::assertTrue($domain->isVerified);
    }

    public function testRecordsDomainAddedEvent(): void
    {
        $id = Uuid::uuid7();
        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );
        // Clear team events
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );

        $events = $domain->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(DomainAdded::class, $events[0]);
        self::assertSame($id, $events[0]->domainId);
        self::assertSame($teamId, $events[0]->teamId);
    }
}

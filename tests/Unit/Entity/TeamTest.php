<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Team;
use App\Events\TeamCreated;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class TeamTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $team = new Team(
            id: $id,
            name: 'Acme Corp',
            slug: 'acme-corp',
            createdAt: $createdAt,
        );

        self::assertSame($id, $team->id);
        self::assertSame('Acme Corp', $team->name);
        self::assertSame('acme-corp', $team->slug);
        self::assertSame($createdAt, $team->createdAt);
        self::assertNull($team->stripeCustomerId);
        self::assertSame('free', $team->plan);
    }

    public function testConstructorWithOptionalFields(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable();

        $team = new Team(
            id: $id,
            name: 'Pro Team',
            slug: 'pro-team',
            createdAt: $createdAt,
            stripeCustomerId: 'cus_123',
            plan: 'pro',
        );

        self::assertSame('cus_123', $team->stripeCustomerId);
        self::assertSame('pro', $team->plan);
    }

    public function testRecordsTeamCreatedEvent(): void
    {
        $id = Uuid::uuid7();

        $team = new Team(
            id: $id,
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );

        $events = $team->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TeamCreated::class, $events[0]);
        self::assertSame($id, $events[0]->teamId);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DomainHealthSnapshotTest extends TestCase
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

        $snapshot = new DomainHealthSnapshot(
            id: $id,
            monitoredDomain: $domain,
            grade: 'A',
            score: 92,
            spfScore: 95,
            dkimScore: 90,
            dmarcScore: 88,
            mxScore: 100,
            blacklistScore: 100,
            checkedAt: $checkedAt,
            recommendations: ['Upgrade DKIM key to 2048-bit'],
            shareHash: 'abc123hash',
        );

        self::assertSame($id, $snapshot->id);
        self::assertSame($domain, $snapshot->monitoredDomain);
        self::assertSame('A', $snapshot->grade);
        self::assertSame(92, $snapshot->score);
        self::assertSame(95, $snapshot->spfScore);
        self::assertSame(90, $snapshot->dkimScore);
        self::assertSame(88, $snapshot->dmarcScore);
        self::assertSame(100, $snapshot->mxScore);
        self::assertSame(100, $snapshot->blacklistScore);
        self::assertSame($checkedAt, $snapshot->checkedAt);
        self::assertSame(['Upgrade DKIM key to 2048-bit'], $snapshot->recommendations);
        self::assertSame('abc123hash', $snapshot->shareHash);
    }

    #[Test]
    public function defaultValues(): void
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

        $snapshot = new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'C',
            score: 60,
            spfScore: 70,
            dkimScore: 50,
            dmarcScore: 55,
            mxScore: 80,
            blacklistScore: 60,
            checkedAt: new \DateTimeImmutable(),
        );

        self::assertSame([], $snapshot->recommendations);
        self::assertNull($snapshot->shareHash);
    }
}

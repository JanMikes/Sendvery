<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class KnownSenderTest extends TestCase
{
    private function createDomain(): MonitoredDomain
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

        return $domain;
    }

    #[Test]
    public function constructorSetsAllFields(): void
    {
        $domain = $this->createDomain();
        $id = Uuid::uuid7();
        $firstSeen = new \DateTimeImmutable('2026-01-01');
        $lastSeen = new \DateTimeImmutable('2026-03-25');

        $sender = new KnownSender(
            id: $id,
            monitoredDomain: $domain,
            sourceIp: '1.2.3.4',
            firstSeenAt: $firstSeen,
            lastSeenAt: $lastSeen,
            totalMessages: 1000,
            passRate: 95.5,
            hostname: 'mail.google.com',
            organization: 'Google',
            label: 'Our Gmail',
            isAuthorized: true,
        );

        self::assertSame($id, $sender->id);
        self::assertSame($domain, $sender->monitoredDomain);
        self::assertSame('1.2.3.4', $sender->sourceIp);
        self::assertSame('mail.google.com', $sender->hostname);
        self::assertSame('Google', $sender->organization);
        self::assertSame('Our Gmail', $sender->label);
        self::assertTrue($sender->isAuthorized);
        self::assertSame($firstSeen, $sender->firstSeenAt);
        self::assertSame($lastSeen, $sender->lastSeenAt);
        self::assertSame(1000, $sender->totalMessages);
        self::assertSame(95.5, $sender->passRate);
    }

    #[Test]
    public function defaultValuesForOptionalFields(): void
    {
        $domain = $this->createDomain();
        $now = new \DateTimeImmutable();

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '5.6.7.8',
            firstSeenAt: $now,
            lastSeenAt: $now,
            totalMessages: 10,
            passRate: 100.0,
        );

        self::assertNull($sender->hostname);
        self::assertNull($sender->organization);
        self::assertNull($sender->label);
        self::assertFalse($sender->isAuthorized);
    }

    #[Test]
    public function updateStats(): void
    {
        $domain = $this->createDomain();
        $now = new \DateTimeImmutable();

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '1.2.3.4',
            firstSeenAt: $now,
            lastSeenAt: $now,
            totalMessages: 100,
            passRate: 90.0,
        );

        $newTime = new \DateTimeImmutable('+1 day');
        $sender->updateStats(
            lastSeenAt: $newTime,
            totalMessages: 200,
            passRate: 95.0,
        );

        self::assertSame($newTime, $sender->lastSeenAt);
        self::assertSame(200, $sender->totalMessages);
        self::assertSame(95.0, $sender->passRate);
    }
}

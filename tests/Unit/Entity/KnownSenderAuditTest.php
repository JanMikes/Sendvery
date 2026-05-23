<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Audit behaviour for the three mutation methods added in TASK-022 —
 * `authorize`, `markUnknown`, `setNotes`. All three must record the
 * actor + timestamp so the inventory page can show "last changed by X
 * on Y" without a separate audit-log table.
 */
final class KnownSenderAuditTest extends TestCase
{
    private function createSender(): KnownSender
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Audit',
            slug: 'audit-'.Uuid::uuid7()->toString(),
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

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '1.2.3.4',
            firstSeenAt: new \DateTimeImmutable('2026-01-01'),
            lastSeenAt: new \DateTimeImmutable('2026-05-01'),
            totalMessages: 100,
            passRate: 90.0,
        );

        return $sender;
    }

    private function createUser(): User
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: 'jane-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();

        return $user;
    }

    #[Test]
    public function authorizeFlipsIsAuthorizedTrueAndRecordsAuditFields(): void
    {
        $sender = $this->createSender();
        $user = $this->createUser();
        $at = new \DateTimeImmutable('2026-05-23 10:30:00');

        $sender->authorize($user, $at);

        self::assertTrue($sender->isAuthorized);
        self::assertSame($at, $sender->updatedAt);
        self::assertSame($user, $sender->updatedByUser);
    }

    #[Test]
    public function markUnknownFlipsIsAuthorizedFalseAndRecordsAuditFields(): void
    {
        $sender = $this->createSender();
        $user = $this->createUser();
        // Pre-authorize, then revoke.
        $sender->isAuthorized = true;
        $at = new \DateTimeImmutable('2026-05-23 10:30:00');

        $sender->markUnknown($user, $at);

        self::assertFalse($sender->isAuthorized);
        self::assertSame($at, $sender->updatedAt);
        self::assertSame($user, $sender->updatedByUser);
    }

    #[Test]
    public function setNotesPersistsTextAndRecordsAuditFields(): void
    {
        $sender = $this->createSender();
        $user = $this->createUser();
        $at = new \DateTimeImmutable('2026-05-23 11:00:00');

        $sender->setNotes('Mailchimp marketing IP — DKIM set up 2026-04-12.', $user, $at);

        self::assertSame('Mailchimp marketing IP — DKIM set up 2026-04-12.', $sender->notes);
        self::assertSame($at, $sender->updatedAt);
        self::assertSame($user, $sender->updatedByUser);
    }

    #[Test]
    public function setNotesAcceptsNullToClearTheField(): void
    {
        $sender = $this->createSender();
        $user = $this->createUser();
        $sender->notes = 'previous note';

        $sender->setNotes(null, $user, new \DateTimeImmutable('2026-05-23 11:00:00'));

        self::assertNull($sender->notes);
    }

    #[Test]
    public function authorizeDoesNotMutateNotes(): void
    {
        $sender = $this->createSender();
        $user = $this->createUser();
        $sender->notes = 'kept';

        $sender->authorize($user, new \DateTimeImmutable());

        self::assertSame('kept', $sender->notes);
    }

    #[Test]
    public function markUnknownDoesNotMutateNotes(): void
    {
        $sender = $this->createSender();
        $user = $this->createUser();
        $sender->isAuthorized = true;
        $sender->notes = 'kept';

        $sender->markUnknown($user, new \DateTimeImmutable());

        self::assertSame('kept', $sender->notes);
    }
}

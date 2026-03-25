<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BetaInvitation;
use App\Value\InvitationStatus;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BetaInvitationTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $sentAt = new \DateTimeImmutable('2026-03-25');
        $expiresAt = new \DateTimeImmutable('2026-04-01');

        $invitation = new BetaInvitation(
            id: $id,
            email: 'beta@example.com',
            invitationToken: 'abc123',
            sentAt: $sentAt,
            expiresAt: $expiresAt,
        );

        self::assertSame($id, $invitation->id);
        self::assertSame('beta@example.com', $invitation->email);
        self::assertSame('abc123', $invitation->invitationToken);
        self::assertSame($sentAt, $invitation->sentAt);
        self::assertSame($expiresAt, $invitation->expiresAt);
        self::assertNull($invitation->invitedBy);
        self::assertSame(InvitationStatus::Pending, $invitation->status);
        self::assertNull($invitation->acceptedAt);
    }

    public function testIsExpiredReturnsTrueWhenPastExpiry(): void
    {
        $invitation = $this->createInvitation(expiresAt: new \DateTimeImmutable('2026-03-25 12:00:00'));

        self::assertTrue($invitation->isExpired(new \DateTimeImmutable('2026-03-25 12:00:01')));
    }

    public function testIsExpiredReturnsFalseWhenBeforeExpiry(): void
    {
        $invitation = $this->createInvitation(expiresAt: new \DateTimeImmutable('2026-03-25 12:00:00'));

        self::assertFalse($invitation->isExpired(new \DateTimeImmutable('2026-03-25 11:59:59')));
    }

    public function testAcceptSetsStatusAndTimestamp(): void
    {
        $invitation = $this->createInvitation();
        $now = new \DateTimeImmutable('2026-03-25 15:00:00');

        $invitation->accept($now);

        self::assertSame(InvitationStatus::Accepted, $invitation->status);
        self::assertSame($now, $invitation->acceptedAt);
    }

    private function createInvitation(?\DateTimeImmutable $expiresAt = null): BetaInvitation
    {
        return new BetaInvitation(
            id: Uuid::uuid7(),
            email: 'test@example.com',
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: $expiresAt ?? new \DateTimeImmutable('+7 days'),
        );
    }
}

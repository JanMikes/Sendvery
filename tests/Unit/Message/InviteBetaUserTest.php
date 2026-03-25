<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\InviteBetaUser;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class InviteBetaUserTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $invitationId = Uuid::uuid7();
        $invitedById = Uuid::uuid7();

        $message = new InviteBetaUser(
            invitationId: $invitationId,
            email: 'beta@example.com',
            invitedById: $invitedById,
        );

        self::assertSame($invitationId, $message->invitationId);
        self::assertSame('beta@example.com', $message->email);
        self::assertSame($invitedById, $message->invitedById);
    }

    public function testInvitedByIdCanBeNull(): void
    {
        $message = new InviteBetaUser(
            invitationId: Uuid::uuid7(),
            email: 'beta@example.com',
            invitedById: null,
        );

        self::assertNull($message->invitedById);
    }
}

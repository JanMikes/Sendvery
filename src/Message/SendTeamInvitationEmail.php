<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Dispatched after a TeamInvitation row is created or resent. Sends the
 * actual email so the worker can retry mailer failures without the inviter
 * waiting on SMTP.
 */
final readonly class SendTeamInvitationEmail
{
    public function __construct(
        public UuidInterface $invitationId,
    ) {
    }
}

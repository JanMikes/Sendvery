<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\TeamRole;
use Ramsey\Uuid\UuidInterface;

final readonly class InviteTeammate
{
    public function __construct(
        public UuidInterface $invitationId,
        public UuidInterface $teamId,
        public UuidInterface $invitedByUserId,
        public string $invitedEmail,
        public TeamRole $role,
    ) {
    }
}

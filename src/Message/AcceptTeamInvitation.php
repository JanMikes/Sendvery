<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class AcceptTeamInvitation
{
    public function __construct(
        public string $invitationToken,
        public UuidInterface $acceptingUserId,
    ) {
    }
}

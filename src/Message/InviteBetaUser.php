<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class InviteBetaUser
{
    public function __construct(
        public UuidInterface $invitationId,
        public string $email,
        public ?UuidInterface $invitedById,
    ) {
    }
}

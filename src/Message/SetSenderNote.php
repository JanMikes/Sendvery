<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class SetSenderNote
{
    public function __construct(
        public UuidInterface $senderId,
        public UuidInterface $teamId,
        public ?string $note,
        public UuidInterface $actorUserId,
    ) {
    }
}

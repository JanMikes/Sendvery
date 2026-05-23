<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class BulkAuthorizeSenders
{
    /**
     * @param list<UuidInterface> $senderIds
     */
    public function __construct(
        public array $senderIds,
        public UuidInterface $teamId,
        public UuidInterface $actorUserId,
    ) {
    }
}

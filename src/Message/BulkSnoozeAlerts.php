<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class BulkSnoozeAlerts
{
    /**
     * @param list<UuidInterface> $alertIds
     */
    public function __construct(
        public array $alertIds,
        public UuidInterface $teamId,
        public \DateTimeImmutable $snoozedUntil,
    ) {
    }
}

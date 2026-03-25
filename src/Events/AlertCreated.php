<?php

declare(strict_types=1);

namespace App\Events;

use App\Value\AlertSeverity;
use App\Value\AlertType;
use Ramsey\Uuid\UuidInterface;

final readonly class AlertCreated
{
    public function __construct(
        public UuidInterface $alertId,
        public UuidInterface $teamId,
        public AlertType $type,
        public AlertSeverity $severity,
        public string $title,
        public ?string $domainName,
    ) {
    }
}

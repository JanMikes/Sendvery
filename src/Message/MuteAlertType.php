<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\AlertType;
use Ramsey\Uuid\UuidInterface;

final readonly class MuteAlertType
{
    public function __construct(
        public UuidInterface $mutedAlertId,
        public UuidInterface $teamId,
        public UuidInterface $domainId,
        public AlertType $alertType,
    ) {
    }
}

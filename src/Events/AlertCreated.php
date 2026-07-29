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
        /**
         * The alert body. Carried on the event because the notification email
         * rendered the title and nothing else — a red "CRITICAL ALERT" headline
         * over a bare IP address, with the explanation sitting in the database
         * one click away. Users reported panicking at it, which is what an
         * alarm that withholds its own reason earns.
         */
        public string $message = '',
    ) {
    }
}

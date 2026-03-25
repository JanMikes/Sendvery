<?php

declare(strict_types=1);

namespace App\Value;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $success,
        public ?string $error,
        public int $mailboxCount,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Value;

readonly final class ConnectionTestResult
{
    public function __construct(
        public bool $success,
        public ?string $error,
        public int $mailboxCount,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class DnsIssue
{
    public function __construct(
        public IssueSeverity $severity,
        public string $message,
        public string $recommendation = '',
    ) {
    }
}

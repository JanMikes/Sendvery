<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\DnsCheckType;

final readonly class DnsMonitoringCheckResult
{
    /**
     * @param array<array{severity: string, message: string, recommendation?: string}> $issues
     * @param array<string, mixed>                                                     $details
     */
    public function __construct(
        public DnsCheckType $type,
        public ?string $rawRecord,
        public bool $isValid,
        public array $issues,
        public array $details,
        public ?string $previousRawRecord,
        public bool $hasChanged,
    ) {
    }
}

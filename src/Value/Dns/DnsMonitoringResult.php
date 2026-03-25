<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\DnsCheckType;

final readonly class DnsMonitoringResult
{
    /**
     * @param array<DnsCheckType, DnsMonitoringCheckResult> $checks
     */
    public function __construct(
        public array $checks,
    ) {
    }

    public function hasAnyChanges(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->hasChanged) {
                return true;
            }
        }

        return false;
    }
}

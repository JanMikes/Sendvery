<?php

declare(strict_types=1);

namespace App\Value\Dns;

readonly final class MxCheckResult
{
    /**
     * @param array<MxRecord> $records
     * @param array<DnsIssue> $issues
     */
    public function __construct(
        public array $records,
        public array $issues,
    ) {
    }

    public function hasRecords(): bool
    {
        return $this->records !== [];
    }

    public function isPassing(): bool
    {
        if (!$this->hasRecords()) {
            return false;
        }

        foreach ($this->records as $record) {
            if ($record->reachable) {
                return true;
            }
        }

        return false;
    }
}

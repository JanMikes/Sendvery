<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class MxCheckResult
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
        return [] !== $this->records;
    }

    /**
     * Validity is a DNS claim: MX records exist and at least one resolves to
     * an IP. Port-25 reachability deliberately does NOT gate this — many
     * hosting providers (including ours) block outbound port 25, so a failed
     * probe usually describes OUR network, not the domain's mail servers.
     * Treating it as failure produced false "MX is broken" critical alerts
     * for perfectly healthy domains.
     */
    public function isPassing(): bool
    {
        if (!$this->hasRecords()) {
            return false;
        }

        foreach ($this->records as $record) {
            if (null !== $record->ip) {
                return true;
            }
        }

        return false;
    }
}

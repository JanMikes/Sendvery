<?php

declare(strict_types=1);

namespace App\Value;

final readonly class ParsedDmarcReport
{
    /**
     * @param array<ParsedDmarcRecord> $records
     */
    public function __construct(
        public string $reporterOrg,
        public string $reporterEmail,
        public string $reportId,
        public \DateTimeImmutable $dateRangeBegin,
        public \DateTimeImmutable $dateRangeEnd,
        public string $policyDomain,
        public DmarcAlignment $policyAdkim,
        public DmarcAlignment $policyAspf,
        public DmarcPolicy $policyP,
        public ?DmarcPolicy $policySp,
        public int $policyPct,
        public array $records,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

final readonly class DmarcReportProcessed
{
    public function __construct(
        public UuidInterface $reportId,
        public UuidInterface $domainId,
        public string $reporterOrg,
        public int $totalRecords,
        public int $passCount,
        public int $failCount,
    ) {
    }
}

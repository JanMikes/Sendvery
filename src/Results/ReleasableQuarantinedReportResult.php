<?php

declare(strict_types=1);

namespace App\Results;

/**
 * One quarantined report that is ready to go back into the normal pipeline,
 * paired with the monitored domain it belongs to.
 *
 * Only the two identifiers the release needs: the report payload is read from
 * the entity (it's a compressed blob, not something to select in a list query).
 */
final readonly class ReleasableQuarantinedReportResult
{
    public function __construct(
        public string $quarantineId,
        public string $domainId,
    ) {
    }

    /** @param array{quarantine_id: string, domain_id: string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            quarantineId: $row['quarantine_id'],
            domainId: $row['domain_id'],
        );
    }
}

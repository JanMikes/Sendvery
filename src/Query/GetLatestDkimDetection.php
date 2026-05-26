<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DkimDetectionResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetLatestDkimDetection
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds
     */
    public function forDomain(string $domainId, array $teamIds): ?DkimDetectionResult
    {
        if ([] === $teamIds) {
            return null;
        }

        $row = $this->database->executeQuery(
            "SELECT
                dcr.details->>'selector'           AS selector,
                dcr.is_valid                        AS key_found,
                dcr.details->>'key_type'            AS key_type,
                dcr.details->>'key_bits'            AS key_bits,
                dcr.details->'detected_providers'   AS detected_providers,
                dcr.details->'matched_providers'    AS matched_providers,
                dcr.checked_at                      AS checked_at
            FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE dcr.monitored_domain_id = :domainId
              AND dcr.type = 'dkim'
              AND md.team_id IN (:teamIds)
            ORDER BY dcr.checked_at DESC
            LIMIT 1",
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        /** @var array{selector: string|null, key_found: bool|string, key_type: string|null, key_bits: int|string|null, detected_providers: string|null, matched_providers: string|null, checked_at: string} $typedRow */
        $typedRow = $row;

        return DkimDetectionResult::fromDatabaseRow($typedRow);
    }
}

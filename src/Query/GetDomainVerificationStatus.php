<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainVerificationStatusResult;
use App\Value\DnsCheckType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

final readonly class GetDomainVerificationStatus
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forTeam(UuidInterface $teamId): ?DomainVerificationStatusResult
    {
        /** @var array{domain_id: string, domain_name: string, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, first_report_at: string|null, dmarc_currently_valid: bool|string|int|null}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                md.spf_verified_at AS spf_verified_at,
                md.dkim_verified_at AS dkim_verified_at,
                md.dmarc_verified_at AS dmarc_verified_at,
                md.first_report_at AS first_report_at,
                (
                    SELECT dcr.is_valid
                    FROM dns_check_result dcr
                    WHERE dcr.monitored_domain_id = md.id
                      AND dcr.type = :dmarcType
                    ORDER BY dcr.checked_at DESC
                    LIMIT 1
                ) AS dmarc_currently_valid
            FROM monitored_domain md
            WHERE md.team_id = :teamId
            ORDER BY md.created_at DESC
            LIMIT 1',
            [
                'teamId' => $teamId->toString(),
                'dmarcType' => DnsCheckType::Dmarc->value,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return DomainVerificationStatusResult::fromDatabaseRow($row);
    }
}

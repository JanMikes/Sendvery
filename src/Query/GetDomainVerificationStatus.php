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
        // consecutive_dmarc_failures counts the trailing run of failing DMARC checks —
        // i.e. how many is_valid=false rows are newer than the most recent is_valid=true
        // row (or all of them, if we've never had a valid check). This is what the
        // severity evaluator uses to require sustained failure before crying wolf:
        // a single transient resolver hiccup yields 1, only 2+ trips Critical.
        /** @var array{domain_id: string, domain_name: string, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, first_report_at: string|null, consecutive_dmarc_failures: int|string|null}|false $row */
        $row = $this->database->executeQuery(
            "SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                md.spf_verified_at AS spf_verified_at,
                md.dkim_verified_at AS dkim_verified_at,
                md.dmarc_verified_at AS dmarc_verified_at,
                md.first_report_at AS first_report_at,
                (
                    SELECT COUNT(*)
                    FROM dns_check_result dcr
                    WHERE dcr.monitored_domain_id = md.id
                      AND dcr.type = :dmarcType
                      AND dcr.is_valid = false
                      AND dcr.checked_at > COALESCE(
                          (
                              SELECT MAX(dcr2.checked_at)
                              FROM dns_check_result dcr2
                              WHERE dcr2.monitored_domain_id = md.id
                                AND dcr2.type = :dmarcType
                                AND dcr2.is_valid = true
                          ),
                          TIMESTAMP '1970-01-01 00:00:00'
                      )
                ) AS consecutive_dmarc_failures
            FROM monitored_domain md
            WHERE md.team_id = :teamId
            ORDER BY md.created_at DESC
            LIMIT 1",
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

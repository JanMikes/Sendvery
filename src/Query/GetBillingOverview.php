<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\BillingOverviewResult;
use Doctrine\DBAL\Connection;

final readonly class GetBillingOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forTeam(string $teamId): BillingOverviewResult
    {
        /** @var array{plan: string, stripe_customer_id: string|null, stripe_subscription_id: string|null, plan_warning_at: string|null, domain_count: int|string, member_count: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                t.plan,
                t.stripe_customer_id,
                t.stripe_subscription_id,
                t.plan_warning_at,
                (SELECT COUNT(*) FROM monitored_domain md WHERE md.team_id = t.id) AS domain_count,
                (SELECT COUNT(*) FROM team_membership tm WHERE tm.team_id = t.id) AS member_count
            FROM team t
            WHERE t.id = :teamId',
            ['teamId' => $teamId],
        )->fetchAssociative();

        if (false === $row) {
            throw new \RuntimeException('Team not found.');
        }

        return BillingOverviewResult::fromDatabaseRow($row);
    }
}

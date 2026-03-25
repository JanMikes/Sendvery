<?php

declare(strict_types=1);

namespace App\Query;

use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;

final readonly class GetTeamPlan
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forTeam(string $teamId): SubscriptionPlan
    {
        $plan = $this->database->executeQuery(
            'SELECT plan FROM team WHERE id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();

        if (false === $plan) {
            throw new \RuntimeException('Team not found.');
        }

        return SubscriptionPlan::from((string) $plan);
    }
}

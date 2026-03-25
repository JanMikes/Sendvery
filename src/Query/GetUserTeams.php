<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\UserTeamResult;
use Doctrine\DBAL\Connection;

final readonly class GetUserTeams
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<UserTeamResult> */
    public function forUser(string $userId): array
    {
        /** @var list<array{team_id: string, team_name: string, team_slug: string, role: string, member_count: int|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT
                t.id AS team_id,
                t.name AS team_name,
                t.slug AS team_slug,
                tm.role AS role,
                (SELECT COUNT(*) FROM team_membership WHERE team_id = t.id) AS member_count
            FROM team_membership tm
            JOIN team t ON t.id = tm.team_id
            WHERE tm.user_id = :userId
            ORDER BY t.name ASC',
            ['userId' => $userId],
        )->fetchAllAssociative();

        return array_map(UserTeamResult::fromDatabaseRow(...), $data);
    }
}

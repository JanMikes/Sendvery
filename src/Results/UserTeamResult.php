<?php

declare(strict_types=1);

namespace App\Results;

final readonly class UserTeamResult
{
    public function __construct(
        public string $teamId,
        public string $teamName,
        public string $teamSlug,
        public string $role,
        public int $memberCount,
    ) {
    }

    /** @param array{team_id: string, team_name: string, team_slug: string, role: string, member_count: int|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            teamId: $row['team_id'],
            teamName: $row['team_name'],
            teamSlug: $row['team_slug'],
            role: $row['role'],
            memberCount: (int) $row['member_count'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\UserTeamResult;
use PHPUnit\Framework\TestCase;

final class UserTeamResultTest extends TestCase
{
    public function testConstructor(): void
    {
        $result = new UserTeamResult(
            teamId: 'team-123',
            teamName: 'Acme Corp',
            teamSlug: 'acme-corp',
            role: 'owner',
            memberCount: 5,
        );

        self::assertSame('team-123', $result->teamId);
        self::assertSame('Acme Corp', $result->teamName);
        self::assertSame('acme-corp', $result->teamSlug);
        self::assertSame('owner', $result->role);
        self::assertSame(5, $result->memberCount);
    }

    public function testFromDatabaseRow(): void
    {
        $row = [
            'team_id' => 'team-456',
            'team_name' => 'Beta Team',
            'team_slug' => 'beta-team',
            'role' => 'admin',
            'member_count' => 3,
        ];

        $result = UserTeamResult::fromDatabaseRow($row);

        self::assertSame('team-456', $result->teamId);
        self::assertSame('Beta Team', $result->teamName);
        self::assertSame('beta-team', $result->teamSlug);
        self::assertSame('admin', $result->role);
        self::assertSame(3, $result->memberCount);
    }
}

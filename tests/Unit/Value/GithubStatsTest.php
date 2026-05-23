<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\GithubStats;
use PHPUnit\Framework\TestCase;

final class GithubStatsTest extends TestCase
{
    public function testFromJsonReturnsInstanceForValidPayload(): void
    {
        $json = json_encode([
            'stars' => 42,
            'forks' => 7,
            'last_commit_at' => '2026-05-20T13:24:00+00:00',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);

        $stats = GithubStats::fromJson($json);

        self::assertInstanceOf(GithubStats::class, $stats);
        self::assertSame(42, $stats->stars);
        self::assertSame(7, $stats->forks);
        self::assertSame('2026-05-20T13:24:00+00:00', $stats->lastCommitAt->format(\DATE_ATOM));
        self::assertSame('main', $stats->defaultBranch);
    }

    public function testFromJsonReturnsNullForMalformedJson(): void
    {
        self::assertNull(GithubStats::fromJson('{not json'));
    }

    public function testFromJsonReturnsNullForJsonScalar(): void
    {
        self::assertNull(GithubStats::fromJson('"just a string"'));
    }

    public function testFromJsonReturnsNullWhenStarsMissing(): void
    {
        $json = json_encode([
            'forks' => 1,
            'last_commit_at' => '2026-05-20T00:00:00+00:00',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }

    public function testFromJsonReturnsNullWhenForksMissing(): void
    {
        $json = json_encode([
            'stars' => 1,
            'last_commit_at' => '2026-05-20T00:00:00+00:00',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }

    public function testFromJsonReturnsNullWhenStarsIsNotInt(): void
    {
        $json = json_encode([
            'stars' => 'forty-two',
            'forks' => 1,
            'last_commit_at' => '2026-05-20T00:00:00+00:00',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }

    public function testFromJsonReturnsNullWhenLastCommitMissing(): void
    {
        $json = json_encode([
            'stars' => 1,
            'forks' => 1,
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }

    public function testFromJsonReturnsNullWhenLastCommitInvalidDate(): void
    {
        $json = json_encode([
            'stars' => 1,
            'forks' => 1,
            'last_commit_at' => 'not-a-date',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }

    public function testFromJsonReturnsNullWhenDefaultBranchMissing(): void
    {
        $json = json_encode([
            'stars' => 1,
            'forks' => 1,
            'last_commit_at' => '2026-05-20T00:00:00+00:00',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }

    public function testFromJsonReturnsNullWhenDefaultBranchEmpty(): void
    {
        $json = json_encode([
            'stars' => 1,
            'forks' => 1,
            'last_commit_at' => '2026-05-20T00:00:00+00:00',
            'default_branch' => '',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull(GithubStats::fromJson($json));
    }
}

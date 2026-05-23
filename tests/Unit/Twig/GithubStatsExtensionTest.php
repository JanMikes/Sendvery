<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\GithubStatsExtension;
use App\Value\GithubStats;
use PHPUnit\Framework\TestCase;

final class GithubStatsExtensionTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/sendvery-github-stats-ext-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0777, true);
    }

    protected function tearDown(): void
    {
        $path = $this->projectDir.'/var/github_stats.json';
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($this->projectDir.'/var')) {
            rmdir($this->projectDir.'/var');
        }
        if (is_dir($this->projectDir)) {
            rmdir($this->projectDir);
        }
    }

    public function testReturnsNullWhenSnapshotFileMissing(): void
    {
        $globals = (new GithubStatsExtension($this->projectDir))->getGlobals();

        self::assertNull($globals['github_stats']);
    }

    public function testReturnsNullWhenSnapshotFileMalformed(): void
    {
        file_put_contents($this->projectDir.'/var/github_stats.json', '{not even json');

        $globals = (new GithubStatsExtension($this->projectDir))->getGlobals();

        self::assertNull($globals['github_stats']);
    }

    public function testReturnsNullWhenSnapshotFileMissingFields(): void
    {
        file_put_contents($this->projectDir.'/var/github_stats.json', json_encode([
            'stars' => 1,
            'forks' => 1,
        ], \JSON_THROW_ON_ERROR));

        $globals = (new GithubStatsExtension($this->projectDir))->getGlobals();

        self::assertNull($globals['github_stats']);
    }

    public function testReturnsGithubStatsInstanceForValidSnapshot(): void
    {
        file_put_contents($this->projectDir.'/var/github_stats.json', json_encode([
            'stars' => 12,
            'forks' => 3,
            'last_commit_at' => '2026-05-15T08:30:00+00:00',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR));

        $globals = (new GithubStatsExtension($this->projectDir))->getGlobals();

        self::assertInstanceOf(GithubStats::class, $globals['github_stats']);
        self::assertSame(12, $globals['github_stats']->stars);
        self::assertSame(3, $globals['github_stats']->forks);
        self::assertSame('main', $globals['github_stats']->defaultBranch);
    }
}

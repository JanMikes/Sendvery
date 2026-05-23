<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Services\Github\FakeGithubApiClient;
use App\Tests\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshGithubStatsCommandTest extends IntegrationTestCase
{
    private string $snapshotPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->snapshotPath = $kernel->getProjectDir().'/var/github_stats.json';

        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }

        parent::tearDown();
    }

    public function testWritesSnapshotWithExpectedFieldsOnSuccess(): void
    {
        $fake = $this->getService(FakeGithubApiClient::class);
        $fake->setResponse([
            'stars' => 123,
            'forks' => 4,
            'last_commit_at' => '2026-05-20T13:24:00Z',
            'default_branch' => 'main',
        ]);

        $exit = $this->tester()->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->snapshotPath);

        $contents = file_get_contents($this->snapshotPath);
        self::assertIsString($contents);

        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'stars' => 123,
            'forks' => 4,
            'last_commit_at' => '2026-05-20T13:24:00Z',
            'default_branch' => 'main',
        ], $decoded);
    }

    public function testReportsSuccessSummaryInOutput(): void
    {
        $fake = $this->getService(FakeGithubApiClient::class);
        $fake->setResponse([
            'stars' => 7,
            'forks' => 1,
            'last_commit_at' => '2026-05-20T00:00:00Z',
            'default_branch' => 'main',
        ]);

        $tester = $this->tester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('7 stars', $display);
        self::assertStringContainsString('1 forks', $display);
    }

    public function testExitsFailureWhenApiReturnsFalse(): void
    {
        $fake = $this->getService(FakeGithubApiClient::class);
        $fake->simulateFailure();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertFileDoesNotExist($this->snapshotPath);
        self::assertStringContainsString('Failed to fetch', $tester->getDisplay());
    }

    public function testLeavesExistingSnapshotIntactOnFailure(): void
    {
        $previous = json_encode([
            'stars' => 999,
            'forks' => 9,
            'last_commit_at' => '2026-01-01T00:00:00Z',
            'default_branch' => 'main',
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($this->snapshotPath, $previous);

        $fake = $this->getService(FakeGithubApiClient::class);
        $fake->simulateFailure();

        $exit = $this->tester()->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame($previous, file_get_contents($this->snapshotPath));
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:opensource:refresh-github-stats'));
    }
}

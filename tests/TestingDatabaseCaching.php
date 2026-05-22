<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpKernel\KernelInterface;

final class TestingDatabaseCaching
{
    private const string CACHE_FILE = __DIR__.'/../var/test-db-hash.cache';

    public static function refresh(KernelInterface $kernel): void
    {
        $currentHash = self::computeHash();
        $cachedHash = self::getCachedHash();

        if ($currentHash === $cachedHash) {
            return;
        }

        self::rebuildDatabase($kernel);
        self::saveCachedHash($currentHash);
    }

    private static function computeHash(): string
    {
        $files = [];

        $migrationsDir = __DIR__.'/../migrations';
        if (is_dir($migrationsDir)) {
            $files = array_merge($files, glob($migrationsDir.'/*.php') ?: []);
        }

        $fixturesDir = __DIR__.'/Fixtures';
        if (is_dir($fixturesDir)) {
            $files = array_merge($files, glob($fixturesDir.'/*.php') ?: []);
        }

        // Include entity files to detect schema changes
        $entityDir = __DIR__.'/../src/Entity';
        if (is_dir($entityDir)) {
            $files = array_merge($files, glob($entityDir.'/*.php') ?: []);
        }

        sort($files);

        $hash = '';
        foreach ($files as $file) {
            $hash .= md5_file($file);
        }

        return md5($hash);
    }

    private static function getCachedHash(): ?string
    {
        if (!file_exists(self::CACHE_FILE)) {
            return null;
        }

        return file_get_contents(self::CACHE_FILE) ?: null;
    }

    private static function saveCachedHash(string $hash): void
    {
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents(self::CACHE_FILE, $hash);
    }

    private static function rebuildDatabase(KernelInterface $kernel): void
    {
        $entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();

        if ([] !== $metadata) {
            $schemaTool->createSchema($metadata);
        }

        self::createMigrationOnlyTables($entityManager->getConnection());
    }

    /**
     * SchemaTool only knows about ORM-mapped entities. Counter tables
     * `team_usage` and `team_ai_usage` are accessed via raw DBAL — they
     * exist only in migrations. Mirror their structure here so integration
     * tests can hit them without running the full migration chain.
     */
    private static function createMigrationOnlyTables(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE team_usage (
                team_id UUID NOT NULL,
                reports_parsed_count INT NOT NULL DEFAULT 0,
                period_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(team_id),
                CONSTRAINT fk_team_usage_team FOREIGN KEY (team_id)
                    REFERENCES team (id) ON DELETE CASCADE
            )
        SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE team_ai_usage (
                team_id UUID NOT NULL,
                on_demand_count INT NOT NULL DEFAULT 0,
                period_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(team_id),
                CONSTRAINT fk_team_ai_usage_team FOREIGN KEY (team_id)
                    REFERENCES team (id) ON DELETE CASCADE
            )
        SQL);
    }
}

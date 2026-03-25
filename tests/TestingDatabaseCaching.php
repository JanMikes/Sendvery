<?php

declare(strict_types=1);

namespace App\Tests;

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
    }
}

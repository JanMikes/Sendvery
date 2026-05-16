<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards a hard project convention: ALL Symfony config is PHP, never YAML.
 *
 * The production Dockerfile does `find config -name "*.yaml" -delete` to remove
 * any YAML configs that Flex recipes drop in (the project standardised on PHP via
 * App::config()). Any YAML file shipped by a recipe that we forget to convert
 * gets silently deleted in the prod image, leaving broken routes / configs that
 * pass in dev and test but blow up in production.
 *
 * Tests run against the source tree, so the YAML is still there during testing —
 * this guard fails CI the moment a recipe adds a YAML config, forcing us to
 * convert it to PHP before merging.
 */
final class NoYamlConfigTest extends TestCase
{
    #[Test]
    public function configDirectoryContainsNoYamlFiles(): void
    {
        $configDir = \dirname(__DIR__, 3).'/config';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($configDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $yamlFiles = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if ('yaml' === $ext || 'yml' === $ext) {
                $yamlFiles[] = substr($file->getPathname(), \strlen($configDir) + 1);
            }
        }

        self::assertSame(
            [],
            $yamlFiles,
            "Project convention: all Symfony config must be PHP, not YAML.\n".
            "The production Dockerfile deletes YAML configs (`find config -name '*.yaml' -delete`),\n".
            "so any YAML left here will silently break in production.\n".
            'Convert these files to PHP equivalents using App::config() or RoutingConfigurator.',
        );
    }
}

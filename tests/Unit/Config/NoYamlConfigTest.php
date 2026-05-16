<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards a hard project convention: ALL Symfony config is PHP, never YAML.
 *
 * The project standardised on PHP via `App::config()` — every package is
 * configured through `config/packages/*.php` and routes through
 * `config/routes/*.php`. Flex recipes occasionally drop YAML files (e.g. the
 * symfony/ux-live-component recipe ships `config/routes/ux_live_component.yaml`).
 *
 * This test fires the moment any such file lands, forcing conversion to PHP
 * before merge. An earlier "fix" deleted YAML at Docker-build time with a
 * `find -delete` — that silently broke production when a recipe-shipped route
 * was nuked. We removed the prune; this test is now the only enforcement.
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

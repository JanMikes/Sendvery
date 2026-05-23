<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\OpenSourceExtension;
use PHPUnit\Framework\TestCase;

final class OpenSourceExtensionTest extends TestCase
{
    public function testEmptyEnvValueIsTreatedAsRepoPrivate(): void
    {
        $globals = (new OpenSourceExtension(''))->getGlobals();

        self::assertFalse($globals['is_repo_public']);
    }

    public function testZeroStringEnvValueIsTreatedAsRepoPrivate(): void
    {
        $globals = (new OpenSourceExtension('0'))->getGlobals();

        self::assertFalse($globals['is_repo_public']);
    }

    public function testOneStringEnvValueIsTreatedAsRepoPublic(): void
    {
        $globals = (new OpenSourceExtension('1'))->getGlobals();

        self::assertTrue($globals['is_repo_public']);
    }

    public function testArbitraryTruthyEnvValueIsTreatedAsRepoPublic(): void
    {
        $globals = (new OpenSourceExtension('true'))->getGlobals();

        self::assertTrue($globals['is_repo_public']);
    }

    public function testGithubUrlIsAlwaysExposed(): void
    {
        $globalsPrivate = (new OpenSourceExtension('0'))->getGlobals();
        $globalsPublic = (new OpenSourceExtension('1'))->getGlobals();

        self::assertSame('https://github.com/janmikes/sendvery', $globalsPrivate['github_url']);
        self::assertSame('https://github.com/janmikes/sendvery', $globalsPublic['github_url']);
    }
}

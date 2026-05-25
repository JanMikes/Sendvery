<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\OpenSourceExtension;
use PHPUnit\Framework\TestCase;

final class OpenSourceExtensionTest extends TestCase
{
    public function testGithubUrlIsExposedAsTwigGlobal(): void
    {
        $globals = (new OpenSourceExtension())->getGlobals();

        self::assertSame('https://github.com/janmikes/sendvery', $globals['github_url']);
    }

    public function testIsRepoPublicGlobalIsRetired(): void
    {
        // TASK-136 — the repo is public now, every gate around it was a lie.
        // Pinning the absence so a future restore would have to retire this test
        // explicitly rather than silently re-introducing the env-gated CTA.
        $globals = (new OpenSourceExtension())->getGlobals();

        self::assertArrayNotHasKey('is_repo_public', $globals);
    }
}

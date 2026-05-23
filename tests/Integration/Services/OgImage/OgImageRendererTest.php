<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\OgImage;

use App\Services\OgImage\OgImageRenderer;
use App\Tests\WebTestCase;
use App\Value\OgImageType;
use PHPUnit\Framework\Attributes\Test;

final class OgImageRendererTest extends WebTestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        assert(is_string($projectDir));
        $this->cacheDir = $projectDir.'/var/og_cache';

        // Each test starts from a clean cache so we genuinely exercise the
        // miss-then-hit path rather than reading a leftover from a sibling test.
        $this->purgeCache();
    }

    protected function tearDown(): void
    {
        $this->purgeCache();
        parent::tearDown();
    }

    #[Test]
    public function cacheMissThenCacheHitReturnSamePath(): void
    {
        $renderer = self::getContainer()->get(OgImageRenderer::class);
        assert($renderer instanceof OgImageRenderer);

        $path1 = $renderer->render(OgImageType::Tool, 'dmarc-checker');
        self::assertFileExists($path1);
        $mtime1 = filemtime($path1);

        // Same args → same cache key → same path; the file must not be
        // re-painted (would update mtime).
        clearstatcache();
        $path2 = $renderer->render(OgImageType::Tool, 'dmarc-checker');
        self::assertSame($path1, $path2);
        self::assertSame($mtime1, filemtime($path2));
    }

    #[Test]
    public function cachePathPartitionsByType(): void
    {
        $renderer = self::getContainer()->get(OgImageRenderer::class);
        assert($renderer instanceof OgImageRenderer);

        $toolPath = $renderer->cachePathFor(OgImageType::Tool, 'spf-checker');
        $kbPath = $renderer->cachePathFor(OgImageType::Kb, 'spf-checker');

        // Same slug, different type → different file, otherwise a tool
        // share with the slug "what-is-dmarc" would collide with the KB
        // article of the same name.
        self::assertNotSame($toolPath, $kbPath);
        self::assertStringContainsString('/tool/', $toolPath);
        self::assertStringContainsString('/kb/', $kbPath);
    }

    private function purgeCache(): void
    {
        foreach (['tool', 'kb', 'health'] as $sub) {
            $dir = $this->cacheDir.'/'.$sub;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}

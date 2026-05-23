<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\OgImage;

use App\Services\OgImage\GdOgImagePainter;
use App\Value\OgImageContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end painter tests — we paint real PNGs into a temp dir and
 * assert dimensions/MIME via `getimagesize`. We deliberately don't mock
 * GD functions; the production behaviour is "GD writes a file", and
 * that's what we test.
 */
final class GdOgImagePainterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/sendvery-og-painter-'.uniqid('', true);
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->purgeRecursive($this->tmpDir);
        }
    }

    private function purgeRecursive(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            assert($entry instanceof \SplFileInfo);
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }

    #[Test]
    public function paintsValidPngOfRequestedDimensions(): void
    {
        $painter = new GdOgImagePainter(__DIR__.'/../../../..');
        $target = $this->tmpDir.'/card.png';

        $painter->paint($this->sampleContent(), $target);

        self::assertFileExists($target);
        $info = getimagesize($target);
        self::assertNotFalse($info);
        self::assertSame(1200, $info[0]);
        self::assertSame(630, $info[1]);
        self::assertSame('image/png', $info['mime']);
    }

    #[Test]
    public function createsTargetDirectoryIfMissing(): void
    {
        $painter = new GdOgImagePainter(__DIR__.'/../../../..');
        // The painter is the production write path; if the cache dir hasn't
        // been provisioned yet (fresh checkout, fresh deploy), it must
        // create the leaf rather than fail.
        $target = $this->tmpDir.'/nested/dir/card.png';

        $painter->paint($this->sampleContent(), $target);

        self::assertFileExists($target);
    }

    #[Test]
    public function usesLogoPngWhenPresentOnDisk(): void
    {
        // Mirror the production layout into a temp project dir so we can
        // exercise the logo-present branch without polluting the real
        // assets directory.
        $projectDir = sys_get_temp_dir().'/sendvery-og-logo-'.uniqid('', true);
        mkdir($projectDir.'/assets/fonts/OgImage', 0o775, true);
        mkdir($projectDir.'/assets/images', 0o775, true);
        copy(__DIR__.'/../../../../assets/fonts/OgImage/Inter-Bold.ttf', $projectDir.'/assets/fonts/OgImage/Inter-Bold.ttf');
        copy(__DIR__.'/../../../../assets/fonts/OgImage/Inter-Regular.ttf', $projectDir.'/assets/fonts/OgImage/Inter-Regular.ttf');
        copy(__DIR__.'/../../../Fixtures/og-logo-fixture.png', $projectDir.'/assets/images/og-logo.png');

        $painter = new GdOgImagePainter($projectDir);
        $target = $this->tmpDir.'/with-logo.png';
        $painter->paint($this->sampleContent(), $target);

        self::assertFileExists($target);
        $info = getimagesize($target);
        self::assertNotFalse($info);
        self::assertSame(1200, $info[0]);

        // Cleanup tmp project dir.
        @unlink($projectDir.'/assets/images/og-logo.png');
        @unlink($projectDir.'/assets/fonts/OgImage/Inter-Bold.ttf');
        @unlink($projectDir.'/assets/fonts/OgImage/Inter-Regular.ttf');
        @rmdir($projectDir.'/assets/images');
        @rmdir($projectDir.'/assets/fonts/OgImage');
        @rmdir($projectDir.'/assets/fonts');
        @rmdir($projectDir.'/assets');
        @rmdir($projectDir);
    }

    #[Test]
    public function wrapsLongTitleAcrossMultipleLines(): void
    {
        $painter = new GdOgImagePainter(__DIR__.'/../../../..');
        $target = $this->tmpDir.'/long.png';

        // Very long title forces the wrapping branch in the painter so the
        // 900px max-width clause stays exercised by tests.
        $painter->paint(new OgImageContent(
            title: 'This is a deliberately verbose article title that must wrap across at least two visible lines',
            subtitle: 'Sendvery Knowledge Base',
            badgeText: 'Guide',
            badgeRgbR: 71,
            badgeRgbG: 85,
            badgeRgbB: 105,
        ), $target);

        $info = getimagesize($target);
        self::assertNotFalse($info);
        self::assertSame(1200, $info[0]);
    }

    private function sampleContent(): OgImageContent
    {
        return new OgImageContent(
            title: 'DMARC Record Checker',
            subtitle: 'Free Email Authentication tool from Sendvery',
            badgeText: 'Email Authentication',
            badgeRgbR: 13,
            badgeRgbG: 148,
            badgeRgbB: 136,
        );
    }
}

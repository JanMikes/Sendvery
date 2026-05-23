<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OgImage;

use App\Exceptions\OgImageContentNotFoundException;
use App\Services\OgImage\ToolOgImageContentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolOgImageContentResolverTest extends TestCase
{
    #[Test]
    public function resolvesKnownToolSlug(): void
    {
        $resolver = new ToolOgImageContentResolver();
        $content = $resolver->resolve('dmarc-checker');

        self::assertSame('DMARC Record Checker', $content->title);
        self::assertSame('Email Authentication', $content->badgeText);
        self::assertStringContainsString('Sendvery', $content->subtitle);
        // Brand teal — must stay stable so every tool card looks like part
        // of the same family.
        self::assertSame(13, $content->badgeRgbR);
        self::assertSame(148, $content->badgeRgbG);
        self::assertSame(136, $content->badgeRgbB);
    }

    #[Test]
    public function throwsForUnknownToolSlug(): void
    {
        $resolver = new ToolOgImageContentResolver();

        $this->expectException(OgImageContentNotFoundException::class);
        $this->expectExceptionMessage('Unknown tool slug "this-tool-does-not-exist"');
        $resolver->resolve('this-tool-does-not-exist');
    }
}

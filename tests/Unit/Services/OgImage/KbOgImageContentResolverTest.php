<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\OgImage;

use App\Exceptions\OgImageContentNotFoundException;
use App\Services\OgImage\KbOgImageContentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KbOgImageContentResolverTest extends TestCase
{
    #[Test]
    public function resolvesKnownArticleSlug(): void
    {
        $resolver = new KbOgImageContentResolver();
        $content = $resolver->resolve('what-is-dmarc');

        self::assertStringContainsString('DMARC', $content->title);
        self::assertSame('Guide', $content->badgeText);
        self::assertStringContainsString('Knowledge Base', $content->subtitle);
    }

    #[Test]
    public function throwsForUnknownArticleSlug(): void
    {
        $resolver = new KbOgImageContentResolver();

        $this->expectException(OgImageContentNotFoundException::class);
        $resolver->resolve('this-article-does-not-exist');
    }
}

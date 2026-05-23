<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\OgImageContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OgImageContentTest extends TestCase
{
    #[Test]
    public function holdsLayoutReadyPayload(): void
    {
        $content = new OgImageContent(
            title: 'DMARC Record Checker',
            subtitle: 'Free Email Authentication tool from Sendvery',
            badgeText: 'Email Authentication',
            badgeRgbR: 13,
            badgeRgbG: 148,
            badgeRgbB: 136,
        );

        self::assertSame('DMARC Record Checker', $content->title);
        self::assertSame('Free Email Authentication tool from Sendvery', $content->subtitle);
        self::assertSame('Email Authentication', $content->badgeText);
        self::assertSame(13, $content->badgeRgbR);
        self::assertSame(148, $content->badgeRgbG);
        self::assertSame(136, $content->badgeRgbB);
    }
}

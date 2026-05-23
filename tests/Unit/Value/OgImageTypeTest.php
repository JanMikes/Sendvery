<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\OgImageType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OgImageTypeTest extends TestCase
{
    #[Test]
    public function casesAreStringBackedAndMatchUrlSlugs(): void
    {
        // String values are baked into public `/og/{type}/{slug}` URLs;
        // breaking them silently invalidates already-shared cards.
        self::assertSame('tool', OgImageType::Tool->value);
        self::assertSame('kb', OgImageType::Kb->value);
        self::assertSame('health', OgImageType::Health->value);
    }

    #[Test]
    public function fromRoundTripsKnownValues(): void
    {
        self::assertSame(OgImageType::Tool, OgImageType::from('tool'));
        self::assertSame(OgImageType::Kb, OgImageType::from('kb'));
        self::assertSame(OgImageType::Health, OgImageType::from('health'));
    }
}

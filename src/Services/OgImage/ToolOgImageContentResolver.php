<?php

declare(strict_types=1);

namespace App\Services\OgImage;

use App\Exceptions\OgImageContentNotFoundException;
use App\Value\OgImageContent;
use App\Value\ToolRegistry;

final readonly class ToolOgImageContentResolver
{
    // Brand primary teal — used uniformly across tool cards so the cards
    // are recognisably part of the same family when seen in a Twitter
    // feed alongside other Sendvery shares.
    private const int BRAND_R = 13;
    private const int BRAND_G = 148;
    private const int BRAND_B = 136;

    public function resolve(string $slug): OgImageContent
    {
        foreach (ToolRegistry::TOOLS as $tool) {
            if ($tool['slug'] === $slug) {
                return new OgImageContent(
                    title: $tool['title'],
                    subtitle: 'Free '.$tool['category'].' tool from Sendvery',
                    badgeText: $tool['category'],
                    badgeRgbR: self::BRAND_R,
                    badgeRgbG: self::BRAND_G,
                    badgeRgbB: self::BRAND_B,
                );
            }
        }

        throw new OgImageContentNotFoundException(sprintf('Unknown tool slug "%s".', $slug));
    }
}

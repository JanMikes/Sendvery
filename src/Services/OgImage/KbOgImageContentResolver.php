<?php

declare(strict_types=1);

namespace App\Services\OgImage;

use App\Controller\KnowledgeBaseIndexController;
use App\Exceptions\OgImageContentNotFoundException;
use App\Value\OgImageContent;

final readonly class KbOgImageContentResolver
{
    // Slate-600-ish — visually distinct from the brand teal used by tool
    // cards so KB shares aren't mistaken for tool shares in a feed.
    private const int KB_R = 71;
    private const int KB_G = 85;
    private const int KB_B = 105;

    public function resolve(string $slug): OgImageContent
    {
        foreach (KnowledgeBaseIndexController::GUIDES as $guide) {
            if ($guide['slug'] === $slug) {
                return new OgImageContent(
                    title: $guide['title'],
                    subtitle: $guide['category'].' · Sendvery Knowledge Base',
                    badgeText: 'Guide',
                    badgeRgbR: self::KB_R,
                    badgeRgbG: self::KB_G,
                    badgeRgbB: self::KB_B,
                );
            }
        }

        throw new OgImageContentNotFoundException(sprintf('Unknown KB article slug "%s".', $slug));
    }
}

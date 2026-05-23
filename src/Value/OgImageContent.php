<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Pre-resolved, layout-ready payload handed from a content resolver to
 * the GD painter. Keeps the painter free of any knowledge about tools,
 * KB articles, or domain-health snapshots — all it sees is "draw this
 * title, this subtitle, this coloured badge".
 *
 * RGB triplet on the badge is pre-computed (rather than a colour name)
 * so the painter can call `imagecolorallocate()` directly without
 * needing to map daisyUI tokens to RGB at render time.
 */
final readonly class OgImageContent
{
    /**
     * @param int<0, 255> $badgeRgbR
     * @param int<0, 255> $badgeRgbG
     * @param int<0, 255> $badgeRgbB
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        public string $badgeText,
        public int $badgeRgbR,
        public int $badgeRgbG,
        public int $badgeRgbB,
    ) {
    }
}

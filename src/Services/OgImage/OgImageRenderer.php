<?php

declare(strict_types=1);

namespace App\Services\OgImage;

use App\Value\OgImageType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Caches dynamic OG image PNGs on disk keyed by `{type}/{md5(version:slug)}`.
 *
 * `SCHEMA_VERSION` is bumped any time the painter layout or default
 * colours change in a way that should invalidate everything currently
 * cached. Old files become orphaned — a `rm -rf var/og_cache/` on
 * deploy reclaims the disk.
 */
final readonly class OgImageRenderer
{
    public const string SCHEMA_VERSION = 'v1';

    public function __construct(
        private ToolOgImageContentResolver $toolResolver,
        private KbOgImageContentResolver $kbResolver,
        private HealthOgImageContentResolver $healthResolver,
        private GdOgImagePainter $painter,
        #[Autowire('%kernel.project_dir%/var/og_cache')]
        private string $ogCacheDir,
    ) {
    }

    public function render(OgImageType $type, string $slug): string
    {
        $cachePath = $this->cachePathFor($type, $slug);

        if (is_file($cachePath)) {
            return $cachePath;
        }

        $content = match ($type) {
            OgImageType::Tool => $this->toolResolver->resolve($slug),
            OgImageType::Kb => $this->kbResolver->resolve($slug),
            OgImageType::Health => $this->healthResolver->resolve($slug),
        };

        $this->painter->paint($content, $cachePath);

        return $cachePath;
    }

    public function cachePathFor(OgImageType $type, string $slug): string
    {
        $key = md5(self::SCHEMA_VERSION.':'.$slug);

        return $this->ogCacheDir.'/'.$type->value.'/'.$key.'.png';
    }
}

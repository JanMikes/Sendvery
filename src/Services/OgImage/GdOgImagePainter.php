<?php

declare(strict_types=1);

namespace App\Services\OgImage;

use App\Value\OgImageContent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GD-backed painter that turns an {@see OgImageContent} into a 1200x630
 * PNG on disk. Pure infrastructure — knows nothing about tools, KB
 * articles, or domain-health snapshots; only renders the abstract card.
 *
 * The painter accepts an optional logo path so the no-logo branch can
 * be exercised in tests, and so the production deployment can ship
 * without a brand logo asset (the wordmark text fallback is used).
 */
final readonly class GdOgImagePainter
{
    private const int CANVAS_WIDTH = 1200;
    private const int CANVAS_HEIGHT = 630;
    private const int ACCENT_BAR_WIDTH = 6;

    // Brand teal — used for the accent bar and the wordmark fallback so
    // every card carries a consistent brand mark even when the logo PNG
    // asset is absent.
    private const int BRAND_R = 13;
    private const int BRAND_G = 148;
    private const int BRAND_B = 136;

    private string $boldFontPath;
    private string $regularFontPath;
    private ?string $logoPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        ?string $logoPath = null,
    ) {
        $this->boldFontPath = $projectDir.'/assets/fonts/OgImage/Inter-Bold.ttf';
        $this->regularFontPath = $projectDir.'/assets/fonts/OgImage/Inter-Regular.ttf';

        // Caller may pin an explicit logo path (tests do this so the
        // logo branch is independent of disk state). When unset, we
        // probe the conventional repo location; absent → text fallback.
        if (null !== $logoPath) {
            $this->logoPath = is_file($logoPath) ? $logoPath : null;
        } else {
            $candidateLogo = $projectDir.'/assets/images/og-logo.png';
            $this->logoPath = is_file($candidateLogo) ? $candidateLogo : null;
        }
    }

    public function paint(OgImageContent $content, string $cacheFilePath): void
    {
        $image = imagecreatetruecolor(self::CANVAS_WIDTH, self::CANVAS_HEIGHT);
        if (false === $image) {
            throw new \RuntimeException('Failed to allocate GD canvas.');
        }

        $this->fillBackground($image);
        $this->drawAccentBar($image);
        $this->drawBrandMark($image);
        $this->drawBadge($image, $content);
        $this->drawTitle($image, $content);
        $this->drawSubtitle($image, $content);
        $this->writePngAtomically($image, $cacheFilePath);
    }

    private function fillBackground(\GdImage $image): void
    {
        // Near-white off-base for a touch of warmth — pure white tends to
        // blend into Twitter / LinkedIn feed backgrounds.
        $bg = imagecolorallocate($image, 248, 250, 252);
        if (false === $bg) {
            throw new \RuntimeException('Failed to allocate background colour.');
        }

        imagefilledrectangle($image, 0, 0, self::CANVAS_WIDTH, self::CANVAS_HEIGHT, $bg);
    }

    private function drawAccentBar(\GdImage $image): void
    {
        $bar = imagecolorallocate($image, self::BRAND_R, self::BRAND_G, self::BRAND_B);
        if (false === $bar) {
            throw new \RuntimeException('Failed to allocate accent-bar colour.');
        }

        imagefilledrectangle($image, 0, 0, self::ACCENT_BAR_WIDTH, self::CANVAS_HEIGHT, $bar);
    }

    private function drawBrandMark(\GdImage $image): void
    {
        if (null !== $this->logoPath) {
            $logo = imagecreatefrompng($this->logoPath);
            if (false === $logo) {
                throw new \RuntimeException('Failed to load OG logo PNG.');
            }

            $logoWidth = imagesx($logo);
            $logoHeight = imagesy($logo);
            imagecopy($image, $logo, 60, 50, 0, 0, $logoWidth, $logoHeight);

            return;
        }

        $brand = imagecolorallocate($image, self::BRAND_R, self::BRAND_G, self::BRAND_B);
        if (false === $brand) {
            throw new \RuntimeException('Failed to allocate wordmark colour.');
        }

        // Plain wordmark — keeps the no-logo branch shippable and
        // reduces visual drift if the logo PNG is added later.
        imagettftext($image, 36, 0, 60, 90, $brand, $this->boldFontPath, 'Sendvery');
    }

    private function drawBadge(\GdImage $image, OgImageContent $content): void
    {
        $badgeColor = imagecolorallocate($image, $content->badgeRgbR, $content->badgeRgbG, $content->badgeRgbB);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        if (false === $badgeColor || false === $textColor) {
            throw new \RuntimeException('Failed to allocate badge colour.');
        }

        $padX = 24;
        $padY = 14;
        $fontSize = 22;
        $bbox = imagettfbbox($fontSize, 0, $this->boldFontPath, $content->badgeText);
        if (false === $bbox) {
            throw new \RuntimeException('Failed to measure badge text.');
        }

        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        $badgeRight = self::CANVAS_WIDTH - 60;
        $badgeTop = 60;
        $badgeLeft = $badgeRight - ($textWidth + 2 * $padX);
        $badgeBottom = $badgeTop + ($textHeight + 2 * $padY);

        imagefilledrectangle($image, $badgeLeft, $badgeTop, $badgeRight, $badgeBottom, $badgeColor);

        // Baseline = badgeTop + padY + textHeight (GD's text baseline is the
        // first arg's Y — we offset by textHeight to land glyphs inside the box).
        $textBaseline = $badgeTop + $padY + $textHeight;
        $textX = $badgeLeft + $padX;
        imagettftext($image, $fontSize, 0, $textX, $textBaseline, $textColor, $this->boldFontPath, $content->badgeText);
    }

    private function drawTitle(\GdImage $image, OgImageContent $content): void
    {
        $color = imagecolorallocate($image, 17, 24, 39); // slate-900
        if (false === $color) {
            throw new \RuntimeException('Failed to allocate title colour.');
        }

        $fontSize = 52;
        $maxLineWidth = 900;
        $lines = $this->wrapText($content->title, $fontSize, $this->boldFontPath, $maxLineWidth);

        // Anchor the block vertically — titles can be 1-3 lines, so we
        // start a bit above centre so subtitle has room below.
        $lineHeight = (int) round($fontSize * 1.25);
        $blockHeight = $lineHeight * count($lines);
        $startY = (int) ((self::CANVAS_HEIGHT / 2) - ($blockHeight / 2) + 30);

        foreach ($lines as $index => $line) {
            $bbox = imagettfbbox($fontSize, 0, $this->boldFontPath, $line);
            if (false === $bbox) {
                throw new \RuntimeException('Failed to measure title text.');
            }

            $lineWidth = $bbox[2] - $bbox[0];
            $x = (int) ((self::CANVAS_WIDTH - $lineWidth) / 2);
            $y = $startY + $index * $lineHeight;
            imagettftext($image, $fontSize, 0, $x, $y, $color, $this->boldFontPath, $line);
        }
    }

    private function drawSubtitle(\GdImage $image, OgImageContent $content): void
    {
        $color = imagecolorallocate($image, 71, 85, 105); // slate-600
        if (false === $color) {
            throw new \RuntimeException('Failed to allocate subtitle colour.');
        }

        $fontSize = 28;
        $bbox = imagettfbbox($fontSize, 0, $this->regularFontPath, $content->subtitle);
        if (false === $bbox) {
            throw new \RuntimeException('Failed to measure subtitle text.');
        }

        $width = $bbox[2] - $bbox[0];
        $x = (int) ((self::CANVAS_WIDTH - $width) / 2);
        $y = self::CANVAS_HEIGHT - 80;
        imagettftext($image, $fontSize, 0, $x, $y, $color, $this->regularFontPath, $content->subtitle);
    }

    /**
     * Greedy word-wrap based on the actual TTF bounding box. Long single
     * words exceed `$maxWidth` rather than being truncated — keeps the
     * implementation predictable; titles are already constrained by the
     * registries.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $fontSize, string $fontPath, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ([] === $words) {
            return [];
        }

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = '' === $current ? $word : $current.' '.$word;
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $candidate);
            if (false === $bbox) {
                throw new \RuntimeException('Failed to measure wrapped text.');
            }

            $width = $bbox[2] - $bbox[0];
            if ($width <= $maxWidth || '' === $current) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ('' !== $current) {
            $lines[] = $current;
        }

        return $lines;
    }

    private function writePngAtomically(\GdImage $image, string $cacheFilePath): void
    {
        $dir = dirname($cacheFilePath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create OG cache directory "%s".', $dir));
        }

        $tmpPath = $cacheFilePath.'.tmp';
        if (false === imagepng($image, $tmpPath)) {
            throw new \RuntimeException(sprintf('Failed to write OG PNG to "%s".', $tmpPath));
        }

        if (!rename($tmpPath, $cacheFilePath)) {
            @unlink($tmpPath);

            throw new \RuntimeException(sprintf('Failed to commit OG PNG to "%s".', $cacheFilePath));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the swap convention documented at the top of `config/placeholders.php`
 * (TASK-023). Every fake entry MUST carry an inline
 * `// TODO(placeholder): replace before launch` marker so `grep TODO(placeholder)`
 * returns a hit per item still pending replacement. This test fails the moment
 * someone replaces an entry's content but forgets to strip the marker, OR
 * strips the marker but leaves the placeholder content in place — either way
 * the partial swap surfaces in CI before it ships.
 */
final class PlaceholdersConventionTest extends TestCase
{
    private const string CONFIG_PATH = __DIR__.'/../../../config/placeholders.php';
    private const string PLACEHOLDER_MARKER = '// TODO(placeholder): replace before launch';
    private const string TOP_BANNER_FRAGMENT = 'TODO(placeholder): see docs/cx-improvement-backlog.md TASK-023';

    /** @var array{testimonials: list<array<string, mixed>>, founder_photo: string|null, linkedin_url: string|null} */
    private static array $config;
    private static string $source;

    public static function setUpBeforeClass(): void
    {
        /** @var array{testimonials: list<array<string, mixed>>, founder_photo: string|null, linkedin_url: string|null} $loaded */
        $loaded = require self::CONFIG_PATH;
        self::$config = $loaded;

        $source = file_get_contents(self::CONFIG_PATH);
        self::assertIsString($source, 'placeholders.php must be readable as text');
        self::$source = $source;
    }

    #[Test]
    public function configFileExists(): void
    {
        self::assertFileExists(self::CONFIG_PATH);
    }

    #[Test]
    public function topOfFileBannerIsPresent(): void
    {
        self::assertStringContainsString(
            self::TOP_BANNER_FRAGMENT,
            self::$source,
            'placeholders.php must carry the top-of-file banner so a future agent grepping for the swap convention finds it from the file itself.',
        );
    }

    #[Test]
    public function everyTestimonialEntryHasPlaceholderMarker(): void
    {
        $markerCount = substr_count(self::$source, self::PLACEHOLDER_MARKER);
        $testimonialCount = \count(self::$config['testimonials']);

        self::assertSame(
            $testimonialCount,
            $markerCount,
            \sprintf(
                'Expected exactly %d "%s" markers (one per testimonial) — found %d. A partial swap that strips markers from some entries but not others must fail CI; an unmatched count means the marker phrase has leaked into a non-entry comment line.',
                $testimonialCount,
                self::PLACEHOLDER_MARKER,
                $markerCount,
            ),
        );
    }

    #[Test]
    public function testimonialListHasSixEntries(): void
    {
        self::assertCount(
            6,
            self::$config['testimonials'],
            'TASK-023 ships 3 visible + 3 bench testimonials. Changing the count without updating the swap convention is a flag.',
        );
    }

    #[Test]
    public function founderPhotoKeyIsReserved(): void
    {
        self::assertArrayHasKey(
            'founder_photo',
            self::$config,
            'founder_photo is reserved for TASK-024 — null is fine, missing key is not.',
        );
    }

    #[Test]
    public function linkedinUrlKeyIsReserved(): void
    {
        self::assertArrayHasKey(
            'linkedin_url',
            self::$config,
            'linkedin_url is reserved for TASK-024 — null is fine, missing key is not.',
        );
    }

    #[Test]
    public function eachTestimonialEntryHasAllRequiredKeys(): void
    {
        $required = ['quote', 'name', 'role', 'company', 'initials'];

        foreach (self::$config['testimonials'] as $index => $entry) {
            foreach ($required as $key) {
                self::assertArrayHasKey(
                    $key,
                    $entry,
                    \sprintf('Testimonial entry %d is missing required key "%s".', $index, $key),
                );
            }
        }
    }
}

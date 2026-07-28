<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Tests\TestSupport\ProjectSource;
use App\Value\WeeklyDigestSection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Stops a section being added to the digest's HTML alternative and nowhere else.
 *
 * The runtime parity test (WeeklyDigestAlternativeParityTest) compares the two
 * alternatives section by section — but it can only compare sections it knows
 * about, and it learns them from {@see WeeklyDigestSection}. Markup that never
 * registers a case is invisible to it: the email grows a block that only HTML
 * readers ever see, every test stays green, and the first person to notice is a
 * customer on a text-only client reading a digest with a hole in it. That is
 * exactly how the "waiting for your review" block shipped HTML-only in the
 * first place.
 *
 * So this guard scans the template itself. Two independent signals, because
 * either one alone is trivially bypassed:
 *
 *  - the section-name comments must match the registry exactly, and
 *  - the number of section headings in the markup must match the number of
 *    registered sections that are supposed to have one.
 *
 * KNOWN LIMIT, stated rather than pretended away: a section introduced with
 * neither a section-name comment nor a `ui.subheading()`/`ui.callout()` call —
 * raw markup with its own conditional — is not visible to either signal. Every
 * section in this template is introduced by one of those two macros, so the
 * bypass requires deliberately writing the new block in a style nothing else in
 * the file uses.
 *
 * Each guard has a paired test that feeds the detector a synthetic violation,
 * so neither is taken on trust.
 */
final class WeeklyDigestSectionGuardTest extends TestCase
{
    private const string TEMPLATE = 'templates/emails/weekly_digest.html.twig';

    #[Test]
    public function everySectionOfTheDigestTemplateIsRegisteredSoBothAlternativesAreHeldToIt(): void
    {
        $declared = self::sectionMarkers(self::templateSource());

        self::assertSame(
            array_values(array_unique($declared)),
            $declared,
            'A section is marked twice in '.self::TEMPLATE.'. One marker per section, or the counts below lie.',
        );

        $registered = self::registeredSectionNames();
        sort($declared);

        self::assertSame(
            $registered,
            $declared,
            'The digest template and WeeklyDigestSection disagree about which sections exist. '
            .'Every section the HTML renders must be registered, so the plain-text alternative is held to it too.',
        );
    }

    #[Test]
    public function noSectionHeadingIsAddedToTheDigestWithoutRegisteringTheSection(): void
    {
        $expected = count(array_filter(
            WeeklyDigestSection::cases(),
            static fn (WeeklyDigestSection $section): bool => $section->hasHeadingInHtml(),
        ));

        self::assertSame(
            $expected,
            self::sectionHeadingCalls(self::templateSource()),
            'The digest template introduces a different number of sections than the registry expects. '
            .'A new heading means a new section: register it in WeeklyDigestSection and render it in '
            .'WeeklyDigestPlainTextRenderer too.',
        );
    }

    #[Test]
    public function theGuardItselfNoticesASectionThatWasNeverRegistered(): void
    {
        $sabotaged = self::templateSource()."\n    {# section: brand_new_thing #}\n";

        self::assertNotSame(
            self::registeredSectionNames(),
            self::sectionMarkers($sabotaged),
            'The marker scan must notice a section name the registry has never heard of.',
        );
    }

    #[Test]
    public function theGuardItselfNoticesAHeadingThatSkippedTheRegistry(): void
    {
        $sabotaged = self::templateSource()."\n    {{ ui.subheading('Sneaked in without a section') }}\n";

        self::assertGreaterThan(
            self::sectionHeadingCalls(self::templateSource()),
            self::sectionHeadingCalls($sabotaged),
            'The heading scan must notice a section introduced straight into the markup.',
        );
    }

    #[Test]
    public function theGuardItselfIgnoresHeadingsQuotedInsideComments(): void
    {
        // Several comments in this template quote the very markup they are
        // explaining. A scan that counted those would flag the explanation as
        // the violation.
        $withCommentary = self::templateSource()."\n    {# never write ui.subheading('like this') here #}\n";

        self::assertSame(
            self::sectionHeadingCalls(self::templateSource()),
            self::sectionHeadingCalls($withCommentary),
            'A heading mentioned in prose is not a section.',
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private static function sectionMarkers(string $twig): array
    {
        preg_match_all('/\{#\s*section:\s*([a-z0-9_]+)\s*#\}/', $twig, $matches);

        return $matches[1];
    }

    /**
     * Comments are blanked first: this template quotes macro calls inside its
     * own explanatory prose.
     */
    private static function sectionHeadingCalls(string $twig): int
    {
        $count = preg_match_all(
            '/\bui\.(?:subheading|callout)\s*\(/',
            ProjectSource::stripTwigComments($twig),
        );
        self::assertNotFalse($count, 'The heading scan must not fail on the digest template.');

        return $count;
    }

    /**
     * @return list<string>
     */
    private static function registeredSectionNames(): array
    {
        $names = array_map(
            static fn (WeeklyDigestSection $section): string => $section->value,
            WeeklyDigestSection::cases(),
        );
        sort($names);

        return $names;
    }

    private static function templateSource(): string
    {
        $path = ProjectSource::projectDir().'/'.self::TEMPLATE;
        $source = file_get_contents($path);
        self::assertIsString($source, 'The digest template must be readable: '.$path);

        return $source;
    }
}

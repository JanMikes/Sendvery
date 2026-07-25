<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\DkimSelectorRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DkimSelectorRegistryTest extends TestCase
{
    private DkimSelectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new DkimSelectorRegistry();
    }

    #[Test]
    public function providerSpecificSelectorsComeBeforeGenericFallback(): void
    {
        $selectors = $this->registry->selectorsFor(['Mailgun']);

        $kIndex = array_search('k1', $selectors, true);
        $defaultIndex = array_search('default', $selectors, true);

        self::assertIsInt($kIndex);
        self::assertIsInt($defaultIndex);
        self::assertLessThan($defaultIndex, $kIndex, 'Mailgun-specific k1 should be probed before generic default');
    }

    #[Test]
    public function seznamIncludesTheCurrentSzn1To3SelectorsBeforeTheLegacyDateBasedOnes(): void
    {
        // Seznam migrated from date-based selectors (szn20221014, ...) to
        // szn1/szn2/szn3 in 2026 — freshly configured domains only publish the
        // new names, so they must be probed (and probed first).
        $selectors = $this->registry->selectorsFor(['Seznam']);

        foreach (['szn1', 'szn2', 'szn3'] as $current) {
            self::assertContains($current, $selectors);
        }

        $szn1Index = array_search('szn1', $selectors, true);
        $legacyIndex = array_search('szn20221014', $selectors, true);
        self::assertIsInt($szn1Index);
        self::assertIsInt($legacyIndex);
        self::assertLessThan($legacyIndex, $szn1Index, 'Current-generation szn1 should be probed before legacy date-based selectors');
    }

    #[Test]
    public function unknownProviderFallsBackToGenericList(): void
    {
        $selectors = $this->registry->selectorsFor(['NeverHeardOfThisProvider']);

        self::assertContains('default', $selectors);
        self::assertContains('google', $selectors);
    }

    #[Test]
    public function noProvidersStillReturnsGenericFallback(): void
    {
        $selectors = $this->registry->selectorsFor([]);

        self::assertNotEmpty($selectors);
        self::assertContains('default', $selectors);
    }

    #[Test]
    public function selectorsAreDeduplicatedAcrossProviders(): void
    {
        $selectors = $this->registry->selectorsFor(['Mailgun', 'Mailchimp']);

        // Both providers list 'k1' — must appear only once
        self::assertCount(1, array_filter($selectors, static fn (string $s): bool => 'k1' === $s));
    }

    #[Test]
    public function providersForSelectorReturnsKnownMatches(): void
    {
        $providers = $this->registry->providersForSelector('google');
        self::assertContains('Google', $providers);

        $providers = $this->registry->providersForSelector('selector1');
        self::assertContains('Microsoft', $providers);

        $providers = $this->registry->providersForSelector('pm');
        self::assertContains('Postmark', $providers);
    }

    #[Test]
    public function providersForSelectorIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->registry->providersForSelector('GOOGLE'),
            $this->registry->providersForSelector('google'),
        );
    }

    #[Test]
    public function providersForSelectorReturnsEmptyForUnknownSelector(): void
    {
        self::assertSame([], $this->registry->providersForSelector('totally-random-xyz'));
    }
}

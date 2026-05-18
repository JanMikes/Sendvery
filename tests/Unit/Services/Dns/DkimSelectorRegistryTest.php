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

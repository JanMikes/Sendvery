<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the unified `<twig:SectionHeader />` rhythm shipped in TASK-026 —
 * each body section on the homepage carries a distinct uppercase eyebrow
 * (replacing the seven+ identical `text-2xl md:text-3xl font-bold` headings).
 * If a section's eyebrow disappears, the homepage flatness this task fixed
 * has regressed.
 */
final class HomepageSectionHeaderTest extends WebTestCase
{
    #[Test]
    public function homepageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function capabilitiesEyebrowRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertStringContainsString(
            'Capabilities',
            (string) $client->getResponse()->getContent(),
            'Section-6 (Feature Highlights) must carry the "Capabilities" eyebrow.',
        );
    }

    #[Test]
    public function commonQuestionsEyebrowRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertStringContainsString(
            'Common questions',
            (string) $client->getResponse()->getContent(),
            'Section-12 (FAQ) must carry the "Common questions" eyebrow.',
        );
    }

    #[Test]
    public function pricingEyebrowRenders(): void
    {
        // The bare word "Pricing" appears in nav + footer links too, so
        // asserting the eyebrow's full class-qualified markup is the only
        // way to actually guard the section-10 eyebrow.
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertStringContainsString(
            '<div class="text-xs font-semibold uppercase tracking-[0.18em] text-primary mb-3">Pricing</div>',
            (string) $client->getResponse()->getContent(),
            'Section-10 must carry the "Pricing" eyebrow via the SectionHeader component.',
        );
    }

    #[Test]
    public function howItWorksEyebrowRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertStringContainsString(
            'How it works',
            (string) $client->getResponse()->getContent(),
            'Section-5 (How it works) must carry the "How it works" eyebrow.',
        );
    }

    #[Test]
    public function sectionHeaderUsesNewLargerTitleClass(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertStringContainsString(
            '<h2 class="text-3xl md:text-4xl font-bold tracking-tight">',
            (string) $client->getResponse()->getContent(),
            'At least one <h2> must use the new SectionHeader class. '
            .'Regression-guard against accidental fallback to the old `text-2xl md:text-3xl font-bold` rhythm.',
        );
    }
}

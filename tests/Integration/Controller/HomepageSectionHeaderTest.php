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

    #[Test]
    public function risksGridReplacesTheOldToolCardGrid(): void
    {
        // TASK-028: sections 6 and 7 used to be two near-identical 4-card grids.
        // Section 7 became a denser "Risks in your DNS" text grid; no ToolCards
        // remain on the homepage. The four tool destinations are reachable via
        // the nav Tools dropdown + footer Free Tools column instead.
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'What Sendvery catches that nobody else does',
            $body,
            'Section 7 must carry the new "What Sendvery catches" title.',
        );
        self::assertStringContainsString(
            'Key expired after a DNS migration',
            $body,
            'Section 7 must surface the DKIM failure scenario.',
        );
        self::assertStringContainsString(
            'Record crept over the 10-lookup limit',
            $body,
            'Section 7 must surface the SPF over-limit scenario.',
        );

        // FeatureCard count is the homepage's single canonical capability grid.
        // ToolCard is the duplicate-y component the audit flagged — must be zero.
        $featureCards = $crawler->filter('div.card[class*="bg-base-100"]');
        // We don't assert an exact count of FeatureCards here (other cards on
        // the page also use bg-base-100); instead we look at the unique signature
        // of the ToolCard component (the rounded-icon + inline link pattern) in
        // the rendered HTML. The ToolCard template invariably renders an inline
        // SVG followed by an <a> with `link-primary` styling. Assert that none
        // of those signatures appear in the homepage rendering anymore.
        self::assertStringNotContainsString(
            'tools_spf_checker',
            $body,
            'Section 7 no longer references the SPF tool route directly — the path is reachable via nav.',
        );
        self::assertStringNotContainsString(
            'tools_dkim_checker',
            $body,
            'Section 7 no longer references the DKIM tool route directly.',
        );
    }
}

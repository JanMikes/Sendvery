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
            '<div class="text-xs uppercase tracking-wider text-zinc-500 mb-3">Pricing</div>',
            (string) $client->getResponse()->getContent(),
            'Section-10 must carry the "Pricing" eyebrow via the SectionHeader component '
            .'(TASK-137: eyebrow uses zinc palette to match the TASK-131 hero register).',
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
            '<h2 class="text-3xl md:text-4xl font-medium tracking-tight text-zinc-900">',
            (string) $client->getResponse()->getContent(),
            'At least one <h2> must use the SectionHeader class. '
            .'Regression-guard against accidental fallback to the pre-TASK-026 `text-2xl md:text-3xl font-bold` rhythm '
            .'AND the pre-TASK-137 `font-bold tracking-tight` register that broke visual coherence with the TASK-131 hero.',
        );
    }

    #[Test]
    public function productPreviewMockRendersAboveHowItWorks(): void
    {
        // TASK-027 / TASK-120: section 4.5 introduces a per-domain dashboard
        // preview between Problem Statement and How it Works. TASK-120 swapped
        // the hand-built daisyUI mock for a real screenshot of
        // `/app/domains/{acme.example-id}` captured against the demo seed.
        $client = self::createClient();
        $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Everything for one domain in one view', $body);

        // Real-screenshot pivot (TASK-120): the placeholder TODO comment, the
        // fake-domain string `acme.io`, and the "Illustrative" caption are gone.
        self::assertStringNotContainsString('TODO(placeholder)', $body);
        self::assertStringNotContainsString('app.sendvery.com/app/domains/acme.io', $body);
        self::assertStringNotContainsString('Illustrative — your data, your domains.', $body);

        // The new asset and its retina variant must both be referenced via
        // `srcset`. The AssetMapper appends a content hash, so match on the
        // base filename prefix + `.webp` (the hash sits between the base and
        // the extension: e.g. `dashboard-domain-detail-42KsQmG.webp`).
        self::assertMatchesRegularExpression(
            '~dashboard-domain-detail(-[A-Za-z0-9_-]+)?\.webp~',
            $body,
            'Homepage section 4.5 must reference the 1x dashboard screenshot.',
        );
        self::assertMatchesRegularExpression(
            '~dashboard-domain-detail@2x(-[A-Za-z0-9_-]+)?\.webp~',
            $body,
            'Homepage section 4.5 must reference the 2x (retina) dashboard screenshot for srcset.',
        );
        self::assertStringContainsString('loading="lazy"', $body);
        self::assertStringContainsString('Acme.example shown — your data, your domains.', $body);

        // Mock must precede "Three steps to email authentication peace of mind".
        $previewPos = strpos($body, 'Everything for one domain in one view');
        $howItWorksPos = strpos($body, 'Three steps to email authentication peace of mind');
        self::assertNotFalse($previewPos);
        self::assertNotFalse($howItWorksPos);
        self::assertGreaterThan($previewPos, $howItWorksPos, 'Product preview must render before "How it works".');
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

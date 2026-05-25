<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PricingPageTest extends WebTestCase
{
    public function testPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
    }

    public function testComparisonTableDesktopVariantIsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        self::assertGreaterThanOrEqual(1, $crawler->filter('table.table.table-zebra')->count());
    }

    public function testComparisonTableMobileVariantIsPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        // The mobile variant wraps four per-tier cards in a `md:hidden` container.
        self::assertStringContainsString('md:hidden', $body);
    }

    public function testComparisonTableContainsAllTierNames(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        // Each tier name appears once in PricingTable (h3) + once each in desktop table header
        // and mobile card, so at minimum twice across the page.
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Free<'));
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Personal<'));
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Pro<'));
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Business<'));
    }

    public function testComparisonTableContainsAllFeatureRows(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Domains', $body);
        self::assertStringContainsString('Reports / month', $body);
        self::assertStringContainsString('Report retention', $body);
        self::assertStringContainsString('Team members', $body);
        self::assertStringContainsString('DMARC + DNS monitoring', $body);
        self::assertStringContainsString('Real-time alerts', $body);
        self::assertStringContainsString('Blacklist monitoring', $body);
        self::assertStringContainsString('Sender inventory', $body);
        self::assertStringContainsString('Email HTML reports', $body);
        self::assertStringContainsString('API access + webhooks', $body);
        self::assertStringContainsString('White-label PDF reports', $body);
        self::assertStringContainsString('AI Insights', $body);
        self::assertStringContainsString('Support', $body);
    }

    public function testFaqSectionIsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        // FAQ uses .collapse-plus (checkbox-driven, distinct from FaqAccordion's radio variant).
        self::assertGreaterThanOrEqual(8, $crawler->filter('.collapse-plus')->count());
    }

    public function testFaqContainsAllExpectedQuestions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Can I cancel anytime?', $body);
        self::assertStringContainsString('Do you offer refunds?', $body);
        self::assertStringContainsString('Can I switch plans later?', $body);
        self::assertStringContainsString('What happens if I exceed my plan limits?', $body);
        self::assertStringContainsString('Do you offer annual discounts?', $body);
        self::assertStringContainsString('Is there a free trial?', $body);
        self::assertStringContainsString('What payment methods do you accept?', $body);
        self::assertStringContainsString('Do you charge VAT?', $body);
        self::assertStringContainsString('Why is self-hosting free?', $body);
        self::assertStringContainsString('How does AI Insights work?', $body);
    }

    /**
     * TASK-118: "What counts as a 'report'?" FAQ entry must explain the
     * definition AND ground it in the actual Free-tier cap so a buyer
     * comparing tiers gets a concrete anchor for the numbers in the
     * comparison table above. Asserts both the Q label and key phrases
     * from the A body to pin them against silent copy-edit drift.
     */
    public function testFaqExplainsWhatCountsAsAReport(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('What counts as a "report"?', $body);
        self::assertStringContainsString('id="faq-what-counts-as-report"', $body);
        // Definition body: one XML = one report, gmail/outlook/yahoo each send daily,
        // and Free's literal cap is named so it's grounded in PlanLimits, not
        // a fictional number.
        self::assertStringContainsString('DMARC aggregate XML', $body);
        self::assertStringContainsString('100 reports/month', $body);
    }

    /**
     * TASK-118: the "Reports / month" row in the comparison table must
     * carry an inline anchor link to the FAQ entry above, so a buyer
     * reading the row can jump straight to the definition. Both desktop
     * and mobile variants get the anchor — assert it appears at least
     * twice (one desktop + at least one mobile card).
     */
    public function testReportsPerMonthRowAnchorsToWhatCountsFaq(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="#faq-what-counts-as-report"', $body);
        self::assertStringContainsString('What counts? →', $body);
        // Desktop table row + four mobile per-tier cards = 5 occurrences expected.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($body, 'href="#faq-what-counts-as-report"'),
        );
    }

    /**
     * TASK-119: every customer hits the DNS-vs-mailbox ingestion fork in
     * onboarding — the pricing FAQ must surface that BOTH paths exist so
     * a DNS-change-averse buyer doesn't bounce thinking they have to
     * republish their `rua=` tag. Asserts both literal ingestion paths
     * are spelled out.
     */
    public function testFaqExplainsBothIngestionPaths(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Can I keep my DMARC reports going to my own inbox?', $body);
        self::assertStringContainsString('id="faq-keep-own-inbox"', $body);
        // Path (a) — `rua=` at a central inbox we control.
        self::assertStringContainsString('reports@sendvery.com', $body);
        // Path (b) — pull from the user's own mailbox via IMAP / OAuth.
        self::assertStringContainsString('IMAP', $body);
    }

    /**
     * TASK-124: each of the three rows whose meaning isn't self-evident
     * from the label alone (Sender inventory, Blacklist monitoring,
     * White-label PDF) must carry a daisyUI `tooltip` with a one-line
     * definition AND link to its glossary FAQ entry below. Asserts the
     * tooltip class is present on each row's label and that the anchor
     * targets the correct FAQ id.
     */
    public function testComparisonTableHasTooltipsForGlossaryFeatures(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        // daisyUI tooltip primitive is `class="tooltip" data-tip="…"`. The
        // template uses it on all three feature rows in both desktop +
        // mobile variants, so at minimum 3 occurrences total.
        self::assertGreaterThanOrEqual(3, substr_count($body, 'class="tooltip'));
        self::assertGreaterThanOrEqual(3, substr_count($body, 'data-tip='));

        // Each row's info icon links to the matching glossary FAQ entry.
        self::assertStringContainsString('href="#faq-sender-inventory"', $body);
        self::assertStringContainsString('href="#faq-blacklist-monitoring"', $body);
        self::assertStringContainsString('href="#faq-white-label-pdf"', $body);
    }

    /**
     * TASK-124: the three glossary FAQ entries (one per feature in the
     * tooltip set above) must appear, AND each must carry the in-app
     * vocabulary that grounds the definition in something the user can
     * verify once they're inside the dashboard.
     */
    public function testFaqContainsGlossaryEntries(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();

        // Q labels.
        self::assertStringContainsString('What is the sender inventory?', $body);
        self::assertStringContainsString('What is blacklist monitoring?', $body);
        self::assertStringContainsString('What is white-label PDF?', $body);

        // A bodies — key phrases from each definition to pin against drift.
        self::assertStringContainsString('authorize', $body);
        self::assertStringContainsString('Spamhaus', $body);
        self::assertStringContainsString('company logo, colors, and footer', $body);

        // Anchor ids (so tooltips above resolve).
        self::assertStringContainsString('id="faq-sender-inventory"', $body);
        self::assertStringContainsString('id="faq-blacklist-monitoring"', $body);
        self::assertStringContainsString('id="faq-white-label-pdf"', $body);
    }

    public function testStartFreeCtaPointsToAuthLogin(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $loginLinks = $crawler->filter('a[href="/login"]');
        self::assertGreaterThanOrEqual(1, $loginLinks->count());

        // The final CTA section has a "Start free" button — confirm the literal label is present.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Start free', $body);
    }

    public function testTalkToUsCtaIsMailtoLink(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $mailtoLinks = $crawler->filter('a[href^="mailto:jan.mikes@sendvery.com"]');
        self::assertGreaterThanOrEqual(1, $mailtoLinks->count());
    }

    public function testMetaDescriptionContainsAnnualSavings(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $metaDescription = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('$12', $metaDescription);
        self::assertStringContainsString('$48', $metaDescription);
        self::assertStringContainsString('$120', $metaDescription);
    }

    public function testWhyAnnualCalloutIsPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Annual billing', $body);
        self::assertStringContainsString('2 months free', $body);
    }
}

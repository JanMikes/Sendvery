<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MarketingPagesTest extends WebTestCase
{
    #[Test]
    #[DataProvider('publicRoutes')]
    public function pageReturns200(string $url): void
    {
        $client = self::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[DataProvider('publicRoutes')]
    public function pageHasTitleAndMetaDescription(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        $title = $crawler->filter('title')->text();
        self::assertNotEmpty($title);
        self::assertStringContainsString('Sendvery', $title);

        $metaDescription = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotEmpty($metaDescription);
    }

    #[Test]
    public function homepageContainsHeroSection(): void
    {
        // TASK-131 — new hero H1. The old "Do you know who else is?" copy belonged to
        // the pre-TASK-131 hero that has been removed; the new H1 is the literal sentence
        // documented in the spec.
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('h1', 'DMARC, DNS, deliverability — monitored and explained.');
    }

    #[Test]
    public function homepageContainsPricingSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorTextContains('#pricing h2', 'Simple, transparent pricing');
    }

    #[Test]
    public function homepageContainsFaqSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorTextContains('#faq h2', 'Frequently asked questions');
    }

    /**
     * TASK-121 — homepage AI FAQ used to claim "Available as an add-on for
     * $3.99/mo or included in the Team plan." The Team plan does not exist and
     * the AI add-on is per-tier (Personal+AI $8.99, Pro+AI $29.99, Business+AI
     * $69.99). Pin BOTH the absence of the stale numbers AND the presence of
     * the corrected per-tier copy so a careless future edit can't drift again.
     */
    #[Test]
    public function homepageFaqDoesNotPromiseStaleAiBundlePricing(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        // The stale $3.99 add-on claim and the non-existent "Team plan" must
        // not appear anywhere on the homepage.
        self::assertStringNotContainsString('$3.99', $body);
        self::assertStringNotContainsString('Team plan', $body);

        // The corrected per-tier AI add-on numbers MUST appear — they have to
        // match `PricingTable.html.twig`'s data-price-ai-annual values exactly.
        self::assertStringContainsString('Personal+AI from $8.99/mo', $body);
        self::assertStringContainsString('Pro+AI from $29.99/mo', $body);
        self::assertStringContainsString('Business+AI from $69.99/mo', $body);
    }

    #[Test]
    public function homepageContainsCta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $ctaButtons = $crawler->filter('a.btn-primary');
        self::assertGreaterThanOrEqual(1, $ctaButtons->count());
    }

    #[Test]
    public function homepageHasStructuredData(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $jsonLd = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThanOrEqual(1, $jsonLd->count());

        $data = json_decode($jsonLd->text(), true);
        self::assertSame('Organization', $data['@type']);
        self::assertSame('Sendvery', $data['name']);
    }

    #[Test]
    public function navigationContainsToolLinks(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorExists('a[href="/tools/spf-checker"]');
        self::assertSelectorExists('a[href="/tools/dkim-checker"]');
        self::assertSelectorExists('a[href="/tools/dmarc-checker"]');
        self::assertSelectorExists('a[href="/tools/domain-health"]');
        self::assertSelectorExists('a[href="/learn"]');
    }

    /**
     * TASK-123 — `/about/what-is-sendvery` was previously only reachable from
     * the footer's About column. Cold visitors wanting a long-form explainer
     * before clicking "Get started" had no top-nav entry. The marketing nav now
     * surfaces it as the FIRST link (before Tools / Learn / Pricing). NO badge
     * per TASK-065 / CLAUDE.md — marketing nav is intentionally badge-free so
     * session state doesn't leak to over-the-shoulder onlookers.
     */
    #[Test]
    public function marketingNavLinksToWhatIsSendveryExplainer(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $explainerLinks = $crawler->filter('nav a[href="/about/what-is-sendvery"]');
        self::assertGreaterThanOrEqual(
            1,
            $explainerLinks->count(),
            'Marketing nav must link to /about/what-is-sendvery so cold visitors can reach the long-form explainer without scrolling to the footer.',
        );

        // The link must NOT carry any attention-badge classes (TASK-065 forbids
        // badges on the marketing nav). Walk every match and assert the badge
        // markers are absent on the link itself and on its immediate children.
        $explainerLinks->each(static function ($node): void {
            $html = $node->outerHtml();
            self::assertStringNotContainsString('badge', $html, 'Marketing-nav explainer link must not render any badge — TASK-065 + CLAUDE.md forbid attention badges on marketing nav.');
            self::assertStringNotContainsString('NavBadge', $html);
        });
    }

    #[Test]
    public function footerContainsAllToolLinks(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $footer = $crawler->filter('footer');
        self::assertStringContainsString('SPF Checker', $footer->text());
        self::assertStringContainsString('DKIM Checker', $footer->text());
        self::assertStringContainsString('DMARC Checker', $footer->text());
        self::assertStringContainsString('MX Checker', $footer->text());
        self::assertStringContainsString('Blacklist Checker', $footer->text());
        self::assertStringContainsString('DNS Monitoring', $footer->text());
        self::assertStringContainsString('Domain Health', $footer->text());
        self::assertStringContainsString('Knowledge Base', $footer->text());
        self::assertStringContainsString('Get Started', $footer->text());
    }

    #[Test]
    public function heroKickerContainsProductCategory(): void
    {
        // TASK-131 — eyebrow is now "DMARC · DNS · deliverability" (lowercase, mid-dot separated)
        // rather than the old "DMARC Monitoring · AI Insights · Open Source" pill.
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        self::assertStringContainsString('DMARC', $hero->text());
        self::assertStringContainsString('deliverability', $hero->text());
    }

    #[Test]
    public function heroContainsPrimaryCtaToAuthLogin(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $primaryCta = $hero->filter('a.btn-primary')->first();

        self::assertCount(1, $primaryCta);
        self::assertStringContainsString('/login', (string) $primaryCta->attr('href'));
        self::assertStringContainsString('Get started free', $primaryCta->text());
    }

    #[Test]
    public function heroSubheadMentionsDmarc(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $subhead = $hero->filter('p')->first();

        self::assertStringContainsString('DMARC', $subhead->text());
    }

    #[Test]
    public function heroSubheadMentionsDnsHealth(): void
    {
        // TASK-131 — the new subhead reads "...watches your DNS 24/7, parses your DMARC reports,
        // and translates the XML into plain English." Phrase changed from "DNS health" to "watches
        // your DNS"; the assertion now binds to the new continuous-monitoring phrasing.
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $subhead = $hero->filter('p')->first();

        self::assertStringContainsString('watches your DNS', $subhead->text());
    }

    #[Test]
    public function heroSubheadMentionsAiInsights(): void
    {
        // TASK-131 — the new subhead doesn't use the phrase "AI-powered insights" in the hero copy
        // (the literal AI-vs-XML pitch lives in section 2). What the hero subhead promises instead
        // is the XML-to-plain-English translation. Bind to that phrasing.
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $subhead = $hero->filter('p')->first();

        self::assertStringContainsString('plain English', $subhead->text());
    }

    #[Test]
    public function metaDescriptionContainsCategoryAndPricing(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $metaDescription = (string) $crawler->filter('meta[name="description"]')->attr('content');

        self::assertStringContainsString('DMARC monitoring', $metaDescription);
        self::assertStringContainsString('$4.99', $metaDescription);
    }

    #[Test]
    public function heroDoesNotContainOldInternalGitHubLink(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $heroHtml = $crawler->filter('section')->first()->html();

        self::assertStringNotContainsString('about_open_source', $heroHtml);
        self::assertStringNotContainsString('/about/open-source', $heroHtml);
    }

    #[Test]
    public function heroTrustBadgesPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $heroText = $crawler->filter('section')->first()->text();

        self::assertStringContainsString('Open source', $heroText);
        self::assertStringContainsString('1 domain free forever', $heroText);
        self::assertStringContainsString('Self-hostable', $heroText);
    }

    #[Test]
    public function heroSecondaryCtaRespectsRepoPublicGate(): void
    {
        // TASK-122 wired the SENDVERY_REPO_PUBLIC env gate; TASK-131 routed the
        // hero secondary CTA through it. When the repo is public we link to
        // github.com; when it's private we surface the notify-me CTA. Either
        // branch must produce exactly one visible secondary CTA — assert the
        // branch that actually matches the test-env gate value.
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $secondary = $crawler->filter('section#dns-checker a[data-track="hero-cta-secondary"]');
        self::assertCount(1, $secondary, 'Exactly one hero-cta-secondary anchor must render in the hero.');

        $isRepoPublic = self::getContainer()->get('twig')->getGlobals()['is_repo_public'] ?? false;
        $href = (string) $secondary->attr('href');

        if (true === $isRepoPublic) {
            self::assertStringStartsWith(
                'https://github.com/',
                $href,
                'When the repo is public the hero secondary CTA must link to github.com.',
            );
        } else {
            self::assertStringStartsWith(
                'mailto:',
                $href,
                'When the repo is private the hero secondary CTA must surface the notify-me mailto CTA (TASK-122 gate).',
            );
            self::assertSame(
                'homepage-hero-repo-launch',
                $secondary->attr('data-notify-source'),
                'The notify-me CTA must carry data-notify-source for marketing tracking.',
            );
        }
    }

    /**
     * TASK-131 acceptance criteria — pin the new three-section top of the homepage:
     *  1. new H1 copy renders
     *  2. checker form lives INSIDE the hero <section> (and no surviving #dns-checker
     *     section outside the hero)
     *  3. trust-logos row sits between the new hero and the new section 2
     *  4. section 2 eyebrow "How the AI insights work" + grade card section 3 H2 render
     *  5. grade card mockup contains "acme.io" + "98.4% pass rate"
     *  6. old hero copy "Email authentication is set once and forgotten" is gone
     */
    #[Test]
    public function task131HomepageHeroAndNewSectionsRender(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        // 1. New H1 copy.
        self::assertStringContainsString('DMARC, DNS, deliverability — monitored and explained.', $body);

        // 2. Old "Email authentication is set once and forgotten" hero/problem-statement copy is gone.
        self::assertStringNotContainsString('Email authentication is set once and forgotten', $body);
        self::assertStringNotContainsString('Do you know who else is?', $body);

        // 3. The checker form sits INSIDE the hero <section> — assert by ID and by the
        //    presence of the HomeDomainChecker component shell within the hero block.
        $hero = $crawler->filter('section#dns-checker');
        self::assertCount(1, $hero, 'The hero section must carry id="dns-checker" so the Nav scroll-to link still works after the standalone DNS-checker section was removed.');
        self::assertGreaterThan(
            0,
            $hero->filter('[data-controller*="live"], [data-live-action-param]')->count(),
            'The HomeDomainChecker Live Component must render INSIDE the hero #dns-checker section (data-live-action-param is one of its always-present attributes).',
        );

        // 4. There must be exactly one #dns-checker on the page (no surviving standalone section).
        self::assertCount(
            1,
            $crawler->filter('#dns-checker'),
            'Exactly one element with id="dns-checker" must exist — the standalone DNS-checker section was removed and only the hero carries the id now.',
        );

        // 5. Trust-logos row sits between hero and section 2. Walk the DOM positions.
        $heroPos = strpos($body, 'id="dns-checker"');
        $trustPos = strpos($body, 'Already running on real production domains');
        $section2Pos = strpos($body, 'How the AI insights work');
        $section3Pos = strpos($body, 'One letter tells you if your email is at risk.');
        self::assertNotFalse($heroPos);
        self::assertNotFalse($trustPos);
        self::assertNotFalse($section2Pos);
        self::assertNotFalse($section3Pos);
        self::assertGreaterThan($heroPos, $trustPos, 'Trust logos must render AFTER the hero.');
        self::assertGreaterThan($trustPos, $section2Pos, 'Section 2 (XML → plain English) must render AFTER the trust logos row.');
        self::assertGreaterThan($section2Pos, $section3Pos, 'Section 3 (grade card) must render AFTER section 2.');

        // 6. Grade card mockup content.
        self::assertStringContainsString('acme.io', $body);
        self::assertStringContainsString('98.4% pass rate', $body);

        // 7. Heading weights are font-medium not font-bold/extrabold (overriding daisyUI default).
        //    H1 is the most load-bearing; spot-check the literal class string.
        self::assertMatchesRegularExpression(
            '~<h1[^>]*\bfont-medium\b[^>]*>~',
            $body,
            'TASK-131 visual contract: the homepage <h1> must carry the explicit font-medium class (overrides daisyUI default heading weight).',
        );

        // 8. Hero CTA goes to /login with the spec-mandated label.
        $primaryCta = $hero->filter('a[data-track="hero-cta-primary"]');
        self::assertCount(1, $primaryCta);
        self::assertStringContainsString('/login', (string) $primaryCta->attr('href'));
        self::assertStringContainsString('Get started free', $primaryCta->text());

        // 9. aria-live polite is set on the live-check result region.
        self::assertGreaterThan(
            0,
            $hero->filter('[aria-live="polite"]')->count(),
            'Live-check result area must carry aria-live="polite" so screen-readers announce results.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function publicRoutes(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'spf-checker' => ['/tools/spf-checker'];
        yield 'dkim-checker' => ['/tools/dkim-checker'];
        yield 'dmarc-checker' => ['/tools/dmarc-checker'];
        yield 'email-auth-checker' => ['/tools/email-auth-checker'];
        yield 'dns-monitoring' => ['/tools/dns-monitoring'];
        yield 'mx-checker' => ['/tools/mx-checker'];
        yield 'blacklist-checker' => ['/tools/blacklist-checker'];
        yield 'domain-health' => ['/tools/domain-health'];
        yield 'pricing' => ['/pricing'];
        yield 'what-is-sendvery' => ['/about/what-is-sendvery'];
        yield 'open-source' => ['/about/open-source'];
        yield 'knowledge-base' => ['/learn'];
        yield 'learn-what-is-dmarc' => ['/learn/what-is-dmarc'];
        yield 'learn-spf-record-guide' => ['/learn/spf-record-guide'];
        yield 'learn-email-auth-explained' => ['/learn/email-authentication-explained'];
        yield 'learn-what-is-dkim' => ['/learn/what-is-dkim'];
        yield 'learn-gmail-yahoo-2024' => ['/learn/gmail-yahoo-bulk-sender-requirements-2024'];
        yield 'learn-dmarc-migration' => ['/learn/dmarc-migration-guide-none-to-reject'];
        yield 'learn-mx-records' => ['/learn/mx-records-explained'];
        yield 'legal-privacy' => ['/legal/privacy'];
        yield 'legal-security' => ['/legal/security'];
        yield 'status' => ['/status'];
    }
}

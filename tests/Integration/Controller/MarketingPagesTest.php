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

    /**
     * TASK-136 — the repo is public, the `is_repo_public` env gate + notify-me
     * mailto fallback are retired. The hero secondary CTA now unconditionally
     * links at github.com. Pin BOTH the github href AND the absence of the
     * prior notify-me data-source so a careless revert can't silently re-wire
     * the env-gated branch back into the hero.
     */
    #[Test]
    public function heroSecondaryCtaLinksToGithub(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $secondary = $crawler->filter('section#dns-checker a[data-track="hero-cta-secondary"]');
        self::assertCount(1, $secondary, 'Exactly one hero-cta-secondary anchor must render in the hero.');

        $href = (string) $secondary->attr('href');
        self::assertStringStartsWith(
            'https://github.com/',
            $href,
            'The hero secondary CTA must link to github.com — the repo is public, no env gate (TASK-136).',
        );

        self::assertNull(
            $secondary->attr('data-notify-source'),
            'The hero secondary CTA must not carry the retired notify-me data-notify-source (TASK-136).',
        );

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('homepage-hero-repo-launch', $body);
        self::assertStringNotContainsString('Notify me when the source ships', $body);
    }

    /**
     * TASK-139 — the homepage "Built for engineers" tech-stack name-drop
     * section was retired. Symfony 8 / PostgreSQL / FrankenPHP badges read as
     * AI-aesthetic clutter that doesn't help a human decide whether Sendvery
     * is the right tool. Pin the absence of every signal so a future restore
     * has to retire this test explicitly.
     */
    #[Test]
    public function homepageDoesNotShowBuiltForEngineersSection(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('Built for engineers', $body);
        self::assertStringNotContainsString('Built for developers who care about infrastructure', $body);
        // The badge labels that lived in the section — each is a tech-stack
        // name-drop we deliberately strip from user-facing surfaces.
        self::assertStringNotContainsString('Symfony 8', $body);
        self::assertStringNotContainsString('FrankenPHP', $body);
    }

    /**
     * TASK-141 — the footer attribution was rewritten from "Built with Symfony
     * & FrankenPHP" (tech-stack name-drop) to "Built with love by Jan Mikeš"
     * with real links at the maintainer profile + source repo.
     */
    #[Test]
    public function footerAttributionNamesMaintainerAndLinksToGithub(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $footer = $crawler->filter('footer');
        $footerText = $footer->text();

        self::assertStringContainsString('Built with love by', $footerText);
        self::assertStringContainsString('Jan Mikeš', $footerText);
        self::assertStringContainsString('Source on GitHub', $footerText);

        // The retired tech-stack name-drop must not survive.
        self::assertStringNotContainsString('Built with Symfony', $footerText);
        self::assertStringNotContainsString('FrankenPHP', $footerText);

        // The attribution surfaces real links — pin both.
        self::assertGreaterThanOrEqual(1, $footer->filter('a[href="https://github.com/janmikes"]')->count(), 'Footer must link to the maintainer\'s GitHub profile.');
        self::assertGreaterThanOrEqual(1, $footer->filter('a[href="https://github.com/janmikes/sendvery"]')->count(), 'Footer must link to the Sendvery source repository.');
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

        // 5. Section ordering: hero → section 2 (XML→plain English) → section 3 (grade card).
        //    The TASK-131-era trust-logos row that sat between hero and section 2 was
        //    removed by the user-driven 6a9d04b hero redesign (alternating section
        //    backgrounds + simplified hero); pin the surviving order without the trust
        //    logos so this test reflects the current intended sequence.
        $heroPos = strpos($body, 'id="dns-checker"');
        $section2Pos = strpos($body, 'How the AI insights work');
        $section3Pos = strpos($body, 'One letter tells you if your email is at risk.');
        self::assertNotFalse($heroPos);
        self::assertNotFalse($section2Pos);
        self::assertNotFalse($section3Pos);
        self::assertGreaterThan($heroPos, $section2Pos, 'Section 2 (XML → plain English) must render AFTER the hero.');
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

    /**
     * TASK-137 — Homepage font register: EVERY <h2> on `/` must carry `font-medium`.
     * The TASK-131 sections introduced an explicit `font-medium tracking-tight
     * text-zinc-900` register on the hero and the next two sections; section 4+
     * still rendered with daisyUI's `font-bold` default, which made the page
     * visually break in half at the seam. This test pins the unified register
     * page-end-to-end: any future edit that re-introduces `font-bold`,
     * `font-semibold`, or `font-extrabold` on a section H2 fails fast.
     *
     * TASK-138 — Step 1/2/3 cards in "How it works" used custom `how-*.webp`
     * illustrations that visually disagreed with the zinc register. The cards
     * now render inline Lucide SVGs inside a zinc-bordered tile; this test
     * pins both the SVG markers and the absence of the legacy <img> tags so
     * the assets stay deleted.
     */
    #[Test]
    public function homepageHeadingsUseUnifiedLighterRegister(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        // Every <h2> on the page must carry font-medium and must NOT carry
        // font-bold / font-semibold / font-extrabold. The page reads as one
        // coherent first-impression surface — no visual seam between sections.
        $headings = $crawler->filter('h2');
        self::assertGreaterThan(
            5,
            $headings->count(),
            'Sanity check: the homepage should have many section H2s.',
        );

        $headings->each(function (\Symfony\Component\DomCrawler\Crawler $node): void {
            $class = (string) $node->attr('class');
            $text = trim($node->text());
            self::assertStringContainsString(
                'font-medium',
                $class,
                sprintf('Section heading "%s" must use the lighter `font-medium` register (got class="%s").', $text, $class),
            );
            self::assertDoesNotMatchRegularExpression(
                '~\b(?:font-bold|font-semibold|font-extrabold)\b~',
                $class,
                sprintf('Section heading "%s" must NOT carry font-bold/semibold/extrabold — they break visual coherence with the hero (got class="%s").', $text, $class),
            );
        });

        // The legacy custom "How it works" illustrations were replaced with
        // inline Lucide icons inside zinc-bordered tiles. Make sure the assets
        // stay deleted and the icon tiles render.
        self::assertStringNotContainsString('how-connect.webp', $body, 'Legacy how-connect.webp illustration must not be re-introduced.');
        self::assertStringNotContainsString('how-monitor.webp', $body, 'Legacy how-monitor.webp illustration must not be re-introduced.');
        self::assertStringNotContainsString('how-act.webp', $body, 'Legacy how-act.webp illustration must not be re-introduced.');

        $iconTiles = $crawler->filter('section .bg-zinc-50.border.border-zinc-200.rounded-lg svg');
        self::assertGreaterThanOrEqual(
            3,
            $iconTiles->count(),
            'Each How-it-works step must render an SVG icon inside a zinc-bordered tile.',
        );
    }

    /**
     * TASK-142 — SEO baseline contract pinned per public page-type.
     *
     * - canonical + og:url are query-string-free (so `/tools/spf-checker?domain=foo`
     *   canonicalizes to `/tools/spf-checker`, not the parameterised URL).
     * - login + auth + invitation pages carry `noindex,follow` so they don't compete
     *   for ranking signal.
     * - robots.txt disallows `/app/`, `/onboarding/`, `/auth/`, `/_components/` so
     *   crawl budget isn't wasted on authenticated surfaces.
     * - sitemap.xml includes every public KB article (including the
     *   `authorizing-senders-explained` slug that was missing in round-7).
     * - Pricing page exposes `SoftwareApplication` JSON-LD with all 4 offer rungs.
     * - The static OG fallback file (`public/images/og-default.webp`) exists on
     *   disk — otherwise every page that doesn't override `og_image` serves a
     *   broken `<meta property="og:image">`.
     */
    #[Test]
    public function publicPagesShipSeoBaseline(): void
    {
        $client = self::createClient();

        // The static OG fallback file must exist on disk — referenced by
        // base.html.twig. Without it, every page lacking a dynamic og:image
        // (home, pricing, about/*, KB index, login, legal, auth flows) would
        // ship a broken Open Graph image to Twitter / LinkedIn / Slack.
        self::assertFileExists(
            \dirname(__DIR__, 3).'/public/images/og-default.webp',
            'The static OG fallback `public/images/og-default.webp` must exist; otherwise pages that do not override `og_image` ship a broken Open Graph image to social previews.',
        );

        // robots.txt must disallow authenticated surfaces so crawl budget
        // isn't wasted on URLs that 302 to login.
        $client->request('GET', '/robots.txt');
        $robots = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /app/', $robots, 'robots.txt must disallow the authenticated dashboard surface (/app/).');
        self::assertStringContainsString('Disallow: /onboarding/', $robots, 'robots.txt must disallow the onboarding flow.');
        self::assertStringContainsString('Disallow: /auth/', $robots, 'robots.txt must disallow auth callback URLs.');

        // The sitemap must include every public KB article. The
        // `authorizing-senders-explained` article was orphaned for a while —
        // pin its presence so future edits cannot drop it again.
        $client->request('GET', '/sitemap.xml');
        self::assertStringContainsString(
            'authorizing-senders-explained',
            (string) $client->getResponse()->getContent(),
            'Sitemap must include every public KB article — `authorizing-senders-explained` was orphaned before; do not let it disappear again.',
        );

        // Canonical URLs strip query strings so a parameterised tool request
        // (e.g. /tools/spf-checker?domain=example.com) does not canonicalize
        // to a unique URL — Google would otherwise treat every checked
        // domain as a near-duplicate of the bare tool page.
        $client->request('GET', '/tools/spf-checker?domain=example.com');
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression(
            '~<link\s+rel="canonical"\s+href="[^"]*?/tools/spf-checker"~',
            $body,
            'Canonical URL on a tool page must point at the bare route — `?domain=…` parameters must NOT leak into the canonical (Google would index every parameterised request as a near-duplicate).',
        );
        self::assertDoesNotMatchRegularExpression(
            '~<link\s+rel="canonical"[^>]*\?[^"]*"~',
            $body,
            'Canonical href must never contain a query string.',
        );

        // Login page must stay out of search results.
        $client->request('GET', '/login');
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression(
            '~<meta\s+name="robots"\s+content="noindex[^"]*"~',
            $body,
            'Login page must declare `noindex` so it does not show up in Google — auth pages add zero organic value.',
        );

        // Pricing page exposes structured data so Google can show price
        // information in rich results.
        $client->request('GET', '/pricing');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"@type": "SoftwareApplication"', $body, 'Pricing page must declare a SoftwareApplication entity in JSON-LD so Google can eligible-detect price rich results.');
        self::assertStringContainsString('"name": "Personal"', $body, 'Pricing JSON-LD must enumerate the Personal tier.');
        self::assertStringContainsString('"name": "Pro"', $body, 'Pricing JSON-LD must enumerate the Pro tier.');
        self::assertStringContainsString('"name": "Business"', $body, 'Pricing JSON-LD must enumerate the Business tier.');
    }

    /**
     * TASK-145 — homepage narrative arc pinned: hero → problem framing →
     * solution (XML→English + grade) → product preview → how it works →
     * pricing (moved EARLIER per user direction) → health-grade reinforcement
     * → features → testimonials → open source → founder bio → FAQ → final CTA.
     *
     * Key pins:
     * - Pricing now sits BEFORE the FAQ + before the deep-dive Features section
     *   (was §10 of 14, now §7 of 14). User: "maybe put pricing slightly higher."
     * - The "Risks in your DNS" problem-framing section sits BETWEEN the hero
     *   and the AI-summary solution section (was §7, now §2). Visitors see
     *   the pain before the pitch.
     * - The screenshot product preview sits BETWEEN the solution sections
     *   and the how-it-works steps.
     */
    #[Test]
    public function homepageFlowsProblemToSolutionToPriceToClose(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $body = (string) $client->getResponse()->getContent();

        $heroPos = strpos($body, 'id="dns-checker"');
        $risksPos = strpos($body, 'What Sendvery catches that nobody else does');
        $aiSummaryPos = strpos($body, 'DMARC reports are written for machines');
        $gradePos = strpos($body, 'One letter tells you if your email is at risk');
        $previewPos = strpos($body, 'Everything for one domain in one view');
        $howItWorksPos = strpos($body, 'Three steps to email authentication peace of mind');
        $pricingPos = strpos($body, 'id="pricing"');
        $featuresPos = strpos($body, 'Everything you need for email authentication');
        $faqPos = strpos($body, 'id="faq"');
        $finalCtaPos = strpos($body, 'Start monitoring your email health today');

        foreach ([
            'hero' => $heroPos, 'risks' => $risksPos, 'ai-summary' => $aiSummaryPos,
            'grade' => $gradePos, 'preview' => $previewPos, 'how-it-works' => $howItWorksPos,
            'pricing' => $pricingPos, 'features' => $featuresPos, 'faq' => $faqPos,
            'final-cta' => $finalCtaPos,
        ] as $label => $pos) {
            self::assertNotFalse($pos, sprintf('Homepage must contain the "%s" section.', $label));
        }

        // Hook → Problem → Solution → Preview → Setup → Price → Features → FAQ → Close.
        self::assertGreaterThan($heroPos, $risksPos, 'Problem framing (the risks visitors did not know about) must render AFTER the hero so the visitor cares before the pitch.');
        self::assertGreaterThan($risksPos, $aiSummaryPos, 'AI-summary solution must render AFTER the problem framing.');
        self::assertGreaterThan($aiSummaryPos, $gradePos, 'Grade-card solution must render AFTER the AI-summary section.');
        self::assertGreaterThan($gradePos, $previewPos, 'Product preview must render AFTER the abstract solution claims so visitors see the tangible artefact.');
        self::assertGreaterThan($previewPos, $howItWorksPos, 'How-it-works must render AFTER the product preview so the visitor knows what they are setting up.');
        self::assertGreaterThan($howItWorksPos, $pricingPos, 'Pricing must follow how-it-works — once the problem + solution + setup are clear, "what does this cost?" is the natural next question.');
        self::assertGreaterThan($pricingPos, $featuresPos, 'Deep-dive feature highlights must render AFTER pricing — feature shopping is lower intent than price-checking.');
        self::assertGreaterThan($featuresPos, $faqPos, 'FAQ must render AFTER feature highlights as the last objection-handling stop before the close.');
        self::assertGreaterThan($faqPos, $finalCtaPos, 'Final CTA must render LAST so the close is the visitor\'s exit option from the page.');
    }

    /**
     * TASK-132 — Section 5 "How it works" Step 1 was the last surface still pitching
     * "Add your domain and connect your DMARC report mailbox" as the primary path.
     * The dashboard (TASK-091/100, TASK-128/130) leads with DNS-first ingestion:
     * publish `rua=mailto:reports@sendvery.com` and let providers route reports to
     * Sendvery centrally — mailbox connection is the fallback for teams that can't
     * change DNS. The homepage marketing copy must mirror the in-product reality a
     * visitor sees within a minute of signing up. Pin BOTH the absence of the
     * legacy Step 1 description AND the presence of the new DNS-first phrasing
     * (`rua=` literal + sentence-case "Point DMARC at Sendvery." title) so a
     * careless future edit can't drift the message back.
     */
    #[Test]
    public function task132HowItWorksStep1LeadsWithDnsFirstIngestion(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();

        // The legacy Step 1 description must not survive anywhere in the rendered
        // markup (Twig {# #} comments are stripped at compile time, so any match
        // here would be a real regression in user-facing copy).
        self::assertStringNotContainsString(
            'Add your domain and connect your DMARC report mailbox',
            $body,
            'Section 5 Step 1 must not pitch mailbox connection as the primary onboarding path — DNS-first is the dashboard reality (TASK-091/100/128).',
        );

        // Locate Step 1 by its eyebrow + walk to the surrounding column so the
        // assertions are scoped to the actual step, not the rest of the page.
        $step1Eyebrow = $crawler->filter('div.text-center:contains("Step 1")')->first();
        self::assertCount(1, $step1Eyebrow, 'Section 5 must contain exactly one "Step 1" column.');

        $step1Html = $step1Eyebrow->html();

        // New title direction — sentence-case, matches the round-6 hero copy register.
        self::assertStringContainsString(
            'Point DMARC at Sendvery.',
            $step1Html,
            'Step 1 title must lead with DNS-first ingestion ("Point DMARC at Sendvery.").',
        );

        // The literal `rua=` token must appear in the body — that's the DNS tag
        // operators publish, and matching IngestionRoutesCallout / PricingFaq
        // teaches the same vocabulary across marketing + dashboard.
        self::assertStringContainsString(
            'rua=',
            $step1Html,
            'Step 1 body must reference the literal `rua=` DMARC tag — same vocabulary as IngestionRoutesCallout + PricingFaq.',
        );

        // The central inbox address must also appear so the step is
        // copy-paste-actionable from the marketing page.
        self::assertStringContainsString(
            'reports@sendvery.com',
            $step1Html,
            'Step 1 must name the central inbox address operators point their `rua=` at.',
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

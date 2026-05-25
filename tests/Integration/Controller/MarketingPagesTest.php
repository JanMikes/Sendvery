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
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorTextContains('h1', 'Do you know who else is');
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
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        self::assertStringContainsString('DMARC Monitoring', $hero->text());
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
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $subhead = $hero->filter('p')->first();

        self::assertStringContainsString('DNS health', $subhead->text());
    }

    #[Test]
    public function heroSubheadMentionsAiInsights(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $subhead = $hero->filter('p')->first();

        self::assertStringContainsString('AI-powered insights', $subhead->text());
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
    public function heroSeeTheSourceLinkPointsAtGithub(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('section')->first();
        $seeTheSourceLinks = $hero->filter('a')->reduce(static function ($node): bool {
            return str_contains($node->text(), 'See the source');
        });

        self::assertGreaterThanOrEqual(1, $seeTheSourceLinks->count());
        self::assertStringStartsWith('https://github.com/', (string) $seeTheSourceLinks->first()->attr('href'));
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

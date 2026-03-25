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
        yield 'beta' => ['/beta'];
        yield 'knowledge-base' => ['/learn'];
        yield 'learn-what-is-dmarc' => ['/learn/what-is-dmarc'];
        yield 'learn-spf-record-guide' => ['/learn/spf-record-guide'];
        yield 'learn-email-auth-explained' => ['/learn/email-authentication-explained'];
    }
}

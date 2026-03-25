<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ToolPagesTest extends WebTestCase
{
    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function tool_page_has_interactive_checker(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="domain"]');
        self::assertSelectorExists('button[type="submit"]');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function tool_page_has_faq_section(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Frequently asked questions');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function tool_page_has_faq_structured_data(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        $jsonLdElements = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThanOrEqual(1, $jsonLdElements->count());

        $hasFaqSchema = false;
        $jsonLdElements->each(function ($node) use (&$hasFaqSchema): void {
            $data = json_decode($node->text(), true);
            if (($data['@type'] ?? '') === 'FAQPage') {
                $hasFaqSchema = true;
            }
        });

        self::assertTrue($hasFaqSchema, 'Page should have FAQPage structured data');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function tool_page_has_breadcrumb_structured_data(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        $jsonLdElements = $crawler->filter('script[type="application/ld+json"]');

        $hasBreadcrumbs = false;
        $jsonLdElements->each(function ($node) use (&$hasBreadcrumbs): void {
            $data = json_decode($node->text(), true);
            if (($data['@type'] ?? '') === 'BreadcrumbList') {
                $hasBreadcrumbs = true;
            }
        });

        self::assertTrue($hasBreadcrumbs, 'Page should have BreadcrumbList structured data');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function tool_page_has_related_tools_links(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Related tools');
    }

    #[Test]
    #[DataProvider('allToolPages')]
    public function tool_page_has_seo_content(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $title = $crawler->filter('title')->text();
        self::assertNotEmpty($title);
        self::assertStringContainsString('Sendvery', $title);

        $metaDescription = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotEmpty($metaDescription);
        self::assertGreaterThan(50, strlen($metaDescription), 'Meta description should be substantial');
    }

    #[Test]
    public function spf_checker_has_correct_h1(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/spf-checker');

        self::assertSelectorTextContains('h1', 'SPF Record Checker');
    }

    #[Test]
    public function dkim_checker_has_selector_input(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/dkim-checker');

        self::assertSelectorExists('input[name="selector"]');
    }

    #[Test]
    public function domain_health_has_correct_h1(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/domain-health');

        self::assertSelectorTextContains('h1', 'Domain Health Check');
    }

    #[Test]
    public function blacklist_checker_shows_coming_soon(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/blacklist-checker');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Blacklist Checker');
    }

    #[Test]
    public function dns_monitoring_shows_beta_cta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/dns-monitoring');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DNS Record Monitoring');
    }

    /** @return iterable<string, array{string}> */
    public static function toolPagesWithCheckers(): iterable
    {
        yield 'spf-checker' => ['/tools/spf-checker'];
        yield 'dkim-checker' => ['/tools/dkim-checker'];
        yield 'dmarc-checker' => ['/tools/dmarc-checker'];
        yield 'email-auth-checker' => ['/tools/email-auth-checker'];
        yield 'mx-checker' => ['/tools/mx-checker'];
        yield 'domain-health' => ['/tools/domain-health'];
    }

    /** @return iterable<string, array{string}> */
    public static function allToolPages(): iterable
    {
        yield from self::toolPagesWithCheckers();
        yield 'blacklist-checker' => ['/tools/blacklist-checker'];
        yield 'dns-monitoring' => ['/tools/dns-monitoring'];
    }
}

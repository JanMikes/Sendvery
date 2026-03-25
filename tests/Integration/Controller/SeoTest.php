<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SeoTest extends WebTestCase
{
    #[Test]
    public function sitemapReturnsValidXml(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml');

        $content = (string) $client->getResponse()->getContent();
        $xml = simplexml_load_string($content);
        self::assertNotFalse($xml, 'Sitemap XML is not valid');

        $namespaces = $xml->getNamespaces();
        self::assertContains('http://www.sitemaps.org/schemas/sitemap/0.9', $namespaces);
    }

    #[Test]
    public function sitemapContainsAllPublicRoutes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sitemap.xml');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('/tools/spf-checker', $content);
        self::assertStringContainsString('/tools/dkim-checker', $content);
        self::assertStringContainsString('/tools/dmarc-checker', $content);
        self::assertStringContainsString('/tools/email-auth-checker', $content);
        self::assertStringContainsString('/tools/dns-monitoring', $content);
        self::assertStringContainsString('/tools/mx-checker', $content);
        self::assertStringContainsString('/tools/blacklist-checker', $content);
        self::assertStringContainsString('/tools/domain-health', $content);
        self::assertStringContainsString('/pricing', $content);
        self::assertStringContainsString('/about/what-is-sendvery', $content);
        self::assertStringContainsString('/about/open-source', $content);
        self::assertStringContainsString('/beta', $content);
        self::assertStringContainsString('/learn', $content);
        self::assertStringContainsString('/learn/what-is-dmarc', $content);
        self::assertStringContainsString('/learn/spf-record-guide', $content);
        self::assertStringContainsString('/learn/email-authentication-explained', $content);
    }

    #[Test]
    public function robotsTxtReturnsCorrectContent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        $contentType = (string) $client->getResponse()->headers->get('Content-Type');
        self::assertStringStartsWith('text/plain', $contentType);

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('User-agent: *', $content);
        self::assertStringContainsString('Allow: /', $content);
        self::assertStringContainsString('sitemap.xml', $content);
    }

    #[Test]
    public function allPagesHaveOpengraphTags(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorExists('meta[property="og:title"]');
        self::assertSelectorExists('meta[property="og:description"]');
        self::assertSelectorExists('meta[property="og:type"]');
        self::assertSelectorExists('meta[property="og:url"]');
    }

    #[Test]
    public function allPagesHaveTwitterCardTags(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorExists('meta[name="twitter:card"]');
        self::assertSelectorExists('meta[name="twitter:title"]');
        self::assertSelectorExists('meta[name="twitter:description"]');
    }

    #[Test]
    public function homepageHasCanonicalUrl(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $canonical = $crawler->filter('link[rel="canonical"]');
        self::assertCount(1, $canonical);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class KnowledgeBaseTest extends WebTestCase
{
    #[Test]
    public function indexReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/learn');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function indexHasTitleAndMeta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn');

        $title = $crawler->filter('title')->text();
        self::assertStringContainsString('Knowledge Base', $title);
        self::assertStringContainsString('Sendvery', $title);

        $metaDescription = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotEmpty($metaDescription);
    }

    #[Test]
    public function indexListsAllGuides(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn');

        self::assertSelectorTextContains('body', 'What is DMARC');
        self::assertSelectorTextContains('body', 'SPF Record');
        self::assertSelectorTextContains('body', 'Email Authentication');
    }

    #[Test]
    public function indexHasStructuredData(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn');

        $jsonLd = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThanOrEqual(1, $jsonLd->count());

        $data = json_decode($jsonLd->text(), true);
        self::assertSame('CollectionPage', $data['@type']);
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideReturns200(string $slug): void
    {
        $client = self::createClient();
        $client->request('GET', '/learn/'.$slug);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideHasTitleAndMeta(string $slug): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn/'.$slug);

        $title = $crawler->filter('title')->text();
        self::assertNotEmpty($title);
        self::assertStringContainsString('Sendvery', $title);

        $metaDescription = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotEmpty($metaDescription);
        self::assertGreaterThan(50, strlen($metaDescription));
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideHasArticleStructuredData(string $slug): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn/'.$slug);

        $jsonLdElements = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThanOrEqual(1, $jsonLdElements->count());

        $hasArticle = false;
        $jsonLdElements->each(function ($node) use (&$hasArticle): void {
            $data = json_decode($node->text(), true);
            if (($data['@type'] ?? '') === 'Article') {
                $hasArticle = true;
            }
        });

        self::assertTrue($hasArticle, 'Guide page should have Article structured data');
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideHasOpengraphArticleType(string $slug): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn/'.$slug);

        $ogType = $crawler->filter('meta[property="og:type"]')->attr('content');
        self::assertSame('article', $ogType);
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideHasMoreGuidesSection(string $slug): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn/'.$slug);

        self::assertSelectorTextContains('body', 'More guides');
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideHasArticleContent(string $slug): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn/'.$slug);

        $article = $crawler->filter('article');
        self::assertGreaterThanOrEqual(1, $article->count());

        $articleText = $article->text();
        self::assertGreaterThan(500, strlen($articleText), 'Article should have substantial content');
    }

    #[Test]
    public function invalidSlugReturns404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/learn/nonexistent-guide');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function indexDoesNotEmbedBetaForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/learn');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('beta-signup-form', $body);
        self::assertStringNotContainsString('href="/beta"', $body);
        self::assertStringNotContainsString('src="/beta"', $body);
    }

    #[Test]
    public function indexHasAuthLoginCta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn');

        $loginCtaLinks = $crawler->filter('a[href="/login"]');
        self::assertGreaterThanOrEqual(1, $loginCtaLinks->count(), 'KB index should have at least one CTA pointing to /login');
        self::assertSelectorTextContains('body', 'Get started free');
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideDoesNotEmbedBetaForm(string $slug): void
    {
        $client = self::createClient();
        $client->request('GET', '/learn/'.$slug);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('beta-signup-form', $body);
        self::assertStringNotContainsString('href="/beta"', $body);
        self::assertStringNotContainsString('src="/beta"', $body);
    }

    #[Test]
    #[DataProvider('guideRoutes')]
    public function guideHasAuthLoginCta(string $slug): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/learn/'.$slug);

        $loginCtaLinks = $crawler->filter('a[href="/login"]');
        self::assertGreaterThanOrEqual(1, $loginCtaLinks->count(), 'KB article should have at least one CTA pointing to /login');
        self::assertSelectorTextContains('body', 'Get started free');
    }

    /** @return iterable<string, array{string}> */
    public static function guideRoutes(): iterable
    {
        yield 'what-is-dmarc' => ['what-is-dmarc'];
        yield 'spf-record-guide' => ['spf-record-guide'];
        yield 'email-authentication-explained' => ['email-authentication-explained'];
    }
}

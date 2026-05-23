<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use App\Value\GithubStats;
use PHPUnit\Framework\Attributes\Test;

final class WhatIsSendveryPageTest extends WebTestCase
{
    #[Test]
    public function pageReturnsSuccessfulResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/what-is-sendvery');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function heroContainsPrimaryCtaToLogin(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        $hero = $crawler->filter('section')->first();
        $primaryCta = $hero->filter('a.btn-primary')->first();

        self::assertCount(1, $primaryCta);
        self::assertStringContainsString('/login', (string) $primaryCta->attr('href'));
        self::assertStringContainsString('Get started free', $primaryCta->text());
    }

    #[Test]
    public function heroContainsWhyAnchorCta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        $hero = $crawler->filter('section')->first();
        $anchorCta = $hero->filter('a[href="#how-different"]');

        self::assertGreaterThanOrEqual(1, $anchorCta->count());
        self::assertStringContainsString('Why Sendvery', $anchorCta->first()->text());
    }

    #[Test]
    public function pageContainsHowDifferentSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        self::assertGreaterThan(0, $crawler->filter('#how-different')->count(), 'Section must have id="how-different"');
        self::assertSelectorTextContains('#how-different h2', 'How is Sendvery different?');
    }

    #[Test]
    public function comparisonPanelMentionsAlternativesAndSendvery(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/what-is-sendvery');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('MXToolbox', $body);
        self::assertStringContainsString('dmarcian', $body);
        self::assertStringContainsString('PowerDMARC', $body);
        self::assertStringContainsString('Sendvery', $body);
        self::assertStringContainsString('$5.99', $body);
    }

    #[Test]
    public function pageContainsAllThreePersonaCards(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        $personaTitles = $crawler->filter('h3.card-title')->each(static fn ($node): string => $node->text());

        self::assertContains('Developer', $personaTitles);
        self::assertContains('Small Business', $personaTitles);
        self::assertContains('Agency', $personaTitles);
    }

    #[Test]
    public function midPageProblemCtaPointsAtDomainHealthTool(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        $domainHealthCtas = $crawler->filter('a[href="/tools/domain-health"]')->reduce(static function ($node): bool {
            return str_contains($node->text(), '30 seconds');
        });

        self::assertGreaterThanOrEqual(1, $domainHealthCtas->count(), 'Mid-page CTA after the problem section must link to /tools/domain-health');
    }

    #[Test]
    public function pageHasAtLeastFourCtaButtons(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        $ctaButtons = $crawler->filter('a.btn');
        self::assertGreaterThanOrEqual(4, $ctaButtons->count(), 'Page must expose hero + 2 mid + final CTAs');
    }

    #[Test]
    public function productPreviewMockContainsThreeFakeDomains(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/what-is-sendvery');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('acme.io', $body);
        self::assertStringContainsString('shop.example.com', $body);
        self::assertStringContainsString('newsletter.example.org', $body);
        self::assertStringContainsString('Illustrative', $body);
    }

    #[Test]
    public function founderBlockquoteIncludesNameAndRole(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        self::assertGreaterThan(0, $crawler->filter('blockquote')->count(), 'Founder quote must render as a blockquote');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('Jan Mikeš', $body);
        self::assertStringContainsString('Founder', $body);
    }

    #[Test]
    public function builtInTheOpenStripRendersGithubStatsWhenPresent(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', new GithubStats(
            stars: 271,
            forks: 9,
            lastCommitAt: new \DateTimeImmutable('2026-05-20T08:00:00+00:00'),
            defaultBranch: 'main',
        ));

        $client->request('GET', '/about/what-is-sendvery');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('Built in the open', $body);
        self::assertStringContainsString('May 20, 2026', $body);
        self::assertStringContainsString('271', $body);
    }

    #[Test]
    public function builtInTheOpenStripFallsBackWhenStatsAreNull(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', null);

        $client->request('GET', '/about/what-is-sendvery');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('Built in the open', $body);
        self::assertStringContainsString('repo opens at launch', $body);
    }

    #[Test]
    public function finalCtaPointsAtLogin(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/what-is-sendvery');

        $sections = $crawler->filter('section');
        $finalSection = $sections->last();

        self::assertStringContainsString('60 seconds', $finalSection->text());
        $primaryCta = $finalSection->filter('a.btn-primary');
        self::assertGreaterThanOrEqual(1, $primaryCta->count());
        self::assertStringContainsString('/login', (string) $primaryCta->first()->attr('href'));
    }
}

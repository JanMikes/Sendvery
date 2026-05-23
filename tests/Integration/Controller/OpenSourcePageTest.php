<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use App\Value\GithubStats;
use PHPUnit\Framework\Attributes\Test;

final class OpenSourcePageTest extends WebTestCase
{
    #[Test]
    public function pageReturnsSuccessfulResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/open-source');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function pageHasHeroHeading(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/open-source');

        self::assertSelectorTextContains('h1', 'Self-host Sendvery free, forever');
    }

    #[Test]
    public function pageHasOpenSourceBadge(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/open-source');

        self::assertSelectorTextContains('.badge', 'Open Source · AGPL-3.0');
    }

    #[Test]
    public function pageHasQuickstartSectionWithAnchor(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        self::assertGreaterThan(0, $crawler->filter('#quickstart')->count(), 'Quickstart section must have id="quickstart"');
        self::assertSelectorTextContains('#quickstart h2', 'Self-host in 60 seconds');
    }

    #[Test]
    public function quickstartContainsThreeCopyableSteps(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $copyControllers = $crawler->filter('#quickstart [data-controller="clipboard-copy"]');
        self::assertGreaterThanOrEqual(3, $copyControllers->count(), 'Quickstart must have three copyable code blocks');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('git clone https://github.com/janmikes/sendvery.git', $body);
        self::assertStringContainsString('docker compose up -d', $body);
    }

    #[Test]
    public function pageHasComparisonTable(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $headings = $crawler->filter('h2')->each(static fn ($node): string => $node->text());
        self::assertContains('Self-host vs Hosted', $headings);

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        foreach (['Cost', 'Time to set up', 'Auto-updates', 'Backups', 'AI insights key', 'Support', 'Data ownership'] as $row) {
            self::assertStringContainsString($row, $body, sprintf('Comparison table must include "%s" row', $row));
        }
    }

    #[Test]
    public function pageHasWhyAgplSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $headings = $crawler->filter('h2')->each(static fn ($node): string => $node->text());
        self::assertContains('Why AGPL-3.0?', $headings);
    }

    #[Test]
    public function pageHasWhatsInTheRepoSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $headings = $crawler->filter('h2')->each(static fn ($node): string => $node->text());
        self::assertContains("What's in the repo?", $headings);

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('src/', $body);
        self::assertStringContainsString('docs/', $body);
        self::assertStringContainsString('tests/', $body);
    }

    #[Test]
    public function endOfPageHasDualCta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $selfHostButtons = $crawler->filter('a[href="#quickstart"]')->reduce(static function ($node): bool {
            return str_contains($node->text(), 'Self-host');
        });
        self::assertGreaterThanOrEqual(1, $selfHostButtons->count(), 'Page must have a self-host CTA pointing at #quickstart');

        $hostedButtons = $crawler->filter('a[href="/login"]')->reduce(static function ($node): bool {
            return str_contains($node->text(), 'hosted') || str_contains($node->text(), 'Try hosted');
        });
        self::assertGreaterThanOrEqual(1, $hostedButtons->count(), 'Page must have a "Try hosted" CTA pointing at /login');
    }

    #[Test]
    public function githubButtonIsDisabledWhenRepoNotPublic(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('is_repo_public', false);
        $twig->addGlobal('github_url', 'https://github.com/janmikes/sendvery');

        $crawler = $client->request('GET', '/about/open-source');

        $disabled = $crawler->filter('main button[disabled]')->reduce(static function ($node): bool {
            return str_contains($node->text(), 'Coming soon');
        });
        self::assertGreaterThanOrEqual(1, $disabled->count(), 'Disabled "Coming soon" button must render when repo is private');

        $mainLinks = $crawler->filter('main a[href="https://github.com/janmikes/sendvery"]');
        self::assertCount(0, $mainLinks, 'Real GitHub link must NOT render inside main while repo is private');
    }

    #[Test]
    public function githubButtonAppearsWhenRepoIsPublic(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('is_repo_public', true);
        $twig->addGlobal('github_url', 'https://github.com/janmikes/sendvery');

        $crawler = $client->request('GET', '/about/open-source');

        $githubLinks = $crawler->filter('main a[href="https://github.com/janmikes/sendvery"]');
        self::assertGreaterThanOrEqual(1, $githubLinks->count(), 'Real GitHub link must render in main when repo is public');

        $disabled = $crawler->filter('main button[disabled]')->reduce(static function ($node): bool {
            return str_contains($node->text(), 'Coming soon');
        });
        self::assertCount(0, $disabled, '"Coming soon" placeholder must NOT render when repo is public');
    }

    #[Test]
    public function githubStatsStripOmittedWhenSnapshotMissing(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', null);

        $client->request('GET', '/about/open-source');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString('GitHub stars', $body, 'Stats strip must be omitted when no snapshot file exists');
    }

    #[Test]
    public function githubStatsStripRendersWhenSnapshotPresent(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', new GithubStats(
            stars: 314,
            forks: 15,
            lastCommitAt: new \DateTimeImmutable('2026-05-20T08:00:00+00:00'),
            defaultBranch: 'main',
        ));

        $client->request('GET', '/about/open-source');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('GitHub stars', $body);
        self::assertStringContainsString('314', $body);
        self::assertStringContainsString('15', $body);
    }
}

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

    /**
     * TASK-136 — the repo is public, the `is_repo_public` env gate is gone, and
     * the quickstart unconditionally renders the three copy-paste commands. Pin
     * the public-branch behaviour AND the absence of the prior notify-me CTA so
     * a future revert can't silently re-introduce the env-gated fallback.
     */
    #[Test]
    public function quickstartUnconditionallyRendersThreeCopyableSteps(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $copyControllers = $crawler->filter('#quickstart [data-controller="clipboard-copy"]');
        self::assertGreaterThanOrEqual(3, $copyControllers->count(), 'Quickstart must have three copyable code blocks now the repo is public.');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('git clone https://github.com/janmikes/sendvery.git', $body);
        self::assertStringContainsString('docker compose up -d', $body);

        // The retired notify-me CTA must NOT survive anywhere on the page.
        self::assertStringNotContainsString('Notify me when the repo goes public', $body);
        self::assertStringNotContainsString('data-notify-source="open-source-repo-launch"', $body);
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

    /**
     * TASK-136 — "What's in the repo?" cards are unconditional now that the
     * repo is public; the env-gated "Source preview at launch" fallback is gone.
     */
    #[Test]
    public function pageHasWhatsInTheRepoSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $headings = $crawler->filter('h2')->each(static fn ($node): string => $node->text());
        self::assertContains("What's in the repo?", $headings);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('src/', $body);
        self::assertStringContainsString('docs/', $body);
        self::assertStringContainsString('tests/', $body);

        // The retired "Source preview at launch" fallback must not appear.
        self::assertStringNotContainsString('Source preview at launch', $body);
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

    /**
     * TASK-136 — the prior "Coming soon — repo opens at launch" disabled button
     * is retired. The page-end attribution now unconditionally links at the
     * canonical github.com/janmikes/sendvery URL.
     */
    #[Test]
    public function pageRendersGithubLinkInsteadOfComingSoonPlaceholder(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/open-source');

        $mainLinks = $crawler->filter('main a[href="https://github.com/janmikes/sendvery"]');
        self::assertGreaterThanOrEqual(1, $mainLinks->count(), 'Real GitHub link must render in main now the repo is public.');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Coming soon — repo opens at launch', $body, 'The retired "Coming soon" placeholder must not survive (TASK-136).');
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

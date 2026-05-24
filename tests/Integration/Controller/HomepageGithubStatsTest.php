<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use App\Value\GithubStats;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the homepage GitHub-stats trust strip (TASK-025). The `github_stats`
 * Twig global is registered by `App\Twig\GithubStatsExtension` and the
 * homepage template reads it in three places: the hero trust badges row,
 * the section-9 "Star on GitHub" button label, and the section-11 technical
 * credibility badge row. Every assertion below verifies the null-safe
 * fallback so a missing/failed cron run never renders zeroes or the literal
 * "null" in the rendered HTML.
 */
final class HomepageGithubStatsTest extends WebTestCase
{
    #[Test]
    public function heroStarsSpanIsRenderedWhenGithubStatsArePresent(): void
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

        $client->request('GET', '/');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('314', $body);
        self::assertStringContainsString('★ on GitHub', $body);
        self::assertStringContainsString('last commit', $body);
        self::assertStringContainsString('May 20', $body);
    }

    #[Test]
    public function heroStarsSpanIsOmittedWhenGithubStatsAreNull(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', null);

        $client->request('GET', '/');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString('★ on GitHub', $body);
        self::assertStringNotContainsString('last commit', $body);
        self::assertStringNotContainsString('null', $body);
        self::assertStringNotContainsString('0 ★', $body);
    }

    #[Test]
    public function starOnGithubButtonLabelIncludesStarCountWhenStatsArePresent(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', new GithubStats(
            stars: 89,
            forks: 4,
            lastCommitAt: new \DateTimeImmutable('2026-05-20T08:00:00+00:00'),
            defaultBranch: 'main',
        ));

        $client->request('GET', '/');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('Star on GitHub (89)', $body);
    }

    #[Test]
    public function starOnGithubButtonLabelOmitsStarCountWhenStatsAreNull(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', null);

        $client->request('GET', '/');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('Star on GitHub', $body);
        self::assertStringNotContainsString('Star on GitHub (', $body);
    }

    #[Test]
    public function agplBadgeIsAppendedWithStarCountWhenStatsArePresent(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', new GithubStats(
            stars: 42,
            forks: 2,
            lastCommitAt: new \DateTimeImmutable('2026-05-20T08:00:00+00:00'),
            defaultBranch: 'main',
        ));

        $client->request('GET', '/');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('AGPL-3.0 · 42 stars', $body);
    }

    #[Test]
    public function agplBadgeIsAbsentWhenStatsAreNull(): void
    {
        $client = self::createClient();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof \Twig\Environment);
        $twig->addGlobal('github_stats', null);

        $client->request('GET', '/');

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString('AGPL-3.0 · ', $body);
    }
}

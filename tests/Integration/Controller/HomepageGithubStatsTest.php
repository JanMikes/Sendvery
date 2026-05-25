<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use App\Value\GithubStats;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the homepage GitHub-stats trust strip (TASK-025). The `github_stats`
 * Twig global is registered by `App\Twig\GithubStatsExtension` and the
 * homepage template reads it in two places now: the hero trust badges row
 * and the section-9 "Star on GitHub" button label. (The prior section-11
 * "Built for engineers" tech-credibility badge row was retired in TASK-139,
 * which is why the AGPL-stars badge assertions live as absence guards rather
 * than presence ones.) Every assertion below verifies the null-safe fallback
 * so a missing/failed cron run never renders zeroes or the literal "null"
 * in the rendered HTML.
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

    /**
     * TASK-139 — the section-11 "Built for engineers" tech-stack badge row
     * (which carried the AGPL-3.0 · {{ stars }} stars badge) was retired.
     * Pin its absence whether the GitHub-stats snapshot exists or not, so a
     * future restore would have to retire this guard explicitly.
     */
    #[Test]
    public function builtForEngineersAgplBadgeIsRetired(): void
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

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('AGPL-3.0 · 42 stars', $body);
        self::assertStringNotContainsString('AGPL-3.0 · ', $body);
    }
}

<?php

declare(strict_types=1);

// Mobile overflow at 360px viewport cannot be asserted here — verify manually after implementation.

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the section-8.7 Founder bio surface on the homepage (TASK-024).
 * Configuration flows from `config/placeholders.php` (`founder_photo` /
 * `linkedin_url` both null at present) through
 * `App\Twig\PlaceholdersExtension` into the `FounderBio.html.twig` component.
 * Tests exercise the live wiring; when real values land, the two
 * null-dependent assertions update in the same PR.
 */
final class HomepageFounderBioTest extends WebTestCase
{
    #[Test]
    public function homepageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function founderSectionExists(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(
            1,
            $crawler->filter('#founder'),
            'Homepage must render exactly one #founder section.',
        );
    }

    #[Test]
    public function founderSectionH2IsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $heading = $crawler->filter('#founder h2');
        self::assertCount(1, $heading, 'Founder section must have exactly one H2.');
        self::assertStringContainsString(
            'Built by one person',
            $heading->text(),
        );
    }

    #[Test]
    public function founderNameIsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertStringContainsString(
            'Jan Mikeš',
            $crawler->filter('#founder')->text(),
            'Founder name "Jan Mikeš" must render inside #founder.',
        );
    }

    #[Test]
    public function githubLinkIsRendered(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertGreaterThanOrEqual(
            1,
            $crawler->filter('#founder a[href*="github.com"]')->count(),
            'Founder section must link to GitHub.',
        );
    }

    #[Test]
    public function emailLinkIsRendered(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertGreaterThanOrEqual(
            1,
            $crawler->filter('#founder a[href^="mailto:"]')->count(),
            'Founder section must include a mailto: link.',
        );
    }

    #[Test]
    public function linkedinChipIsAbsentWhenConfigIsNull(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(
            0,
            $crawler->filter('#founder a[href*="linkedin.com"]'),
            'LinkedIn chip must be absent while `linkedin_url` in config/placeholders.php is null.',
        );
    }

    #[Test]
    public function initialsPlaceholderAvatarIsRenderedWhenFounderPhotoIsNull(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(
            1,
            $crawler->filter('#founder .avatar-placeholder'),
            'Initials-placeholder avatar must render while `founder_photo` in config/placeholders.php is null.',
        );
        self::assertCount(
            0,
            $crawler->filter('#founder img'),
            'No <img> must render in #founder while `founder_photo` is null — placeholder branch only.',
        );
    }

    #[Test]
    public function bioParagraphCountIsThree(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(
            3,
            $crawler->filter('#founder p'),
            'Founder bio must consist of exactly three paragraphs.',
        );
    }
}

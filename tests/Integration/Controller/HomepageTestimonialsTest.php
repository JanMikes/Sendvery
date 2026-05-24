<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the section-8.5 Testimonials surface on the homepage (TASK-023).
 * The data lives in `config/placeholders.php` and flows through
 * `App\Twig\PlaceholdersExtension` — these tests assert the wiring is intact
 * and that the `|slice(0, 3)` hard-cut keeps the bench testimonials off the
 * rendered page.
 */
final class HomepageTestimonialsTest extends WebTestCase
{
    #[Test]
    public function homepageWith200Response(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function testimonialsSectionH2IsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $heading = $crawler->filter('#testimonials h2');
        self::assertCount(1, $heading, 'Testimonials section must have exactly one H2.');
        self::assertStringContainsString(
            'Trusted by the people who actually read DMARC reports',
            $heading->text(),
        );
    }

    #[Test]
    public function firstThreeTestimonialNamesAreVisible(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $section = $crawler->filter('#testimonials')->text();

        foreach (['Maya Hernandez', 'Tomáš Novák', 'Priya Iyer'] as $name) {
            self::assertStringContainsString(
                $name,
                $section,
                \sprintf('First-three visible testimonial "%s" must render in #testimonials.', $name),
            );
        }
    }

    #[Test]
    public function benchTestimonialNamesAreNotRendered(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $section = $crawler->filter('#testimonials')->text();

        foreach (['David Okafor', 'Anna Lindqvist', 'Marco Bianchi'] as $name) {
            self::assertStringNotContainsString(
                $name,
                $section,
                \sprintf('Bench testimonial "%s" must NOT render — the |slice(0, 3) hard-cut keeps them off the page.', $name),
            );
        }
    }

    #[Test]
    public function testimonialsSectionContainsThreeCards(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(
            3,
            $crawler->filter('#testimonials .card'),
            'Exactly three testimonial cards must render even though config/placeholders.php carries six entries.',
        );
    }

    #[Test]
    public function initialsAvatarsAreRendered(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $section = $crawler->filter('#testimonials')->text();

        foreach (['MH', 'TN', 'PI'] as $initials) {
            self::assertStringContainsString(
                $initials,
                $section,
                \sprintf('Initials "%s" must render inside #testimonials (placeholder avatars).', $initials),
            );
        }
    }
}

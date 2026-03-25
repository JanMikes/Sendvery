<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\BetaSignup;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class BetaSignupTest extends WebTestCase
{
    #[Test]
    public function beta_page_returns_200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/beta');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function beta_page_has_signup_form(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/beta');

        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('button[type="submit"]');
    }

    #[Test]
    public function beta_page_has_title_and_meta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/beta');

        $title = $crawler->filter('title')->text();
        self::assertStringContainsString('Sendvery', $title);

        $metaDescription = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotEmpty($metaDescription);
    }

    #[Test]
    public function submit_valid_signup_shows_success(): void
    {
        $client = self::createClient();
        $client->request('POST', '/beta', [
            'email' => 'test-signup-' . Uuid::uuid7()->toString() . '@example.com',
            'source' => 'beta-page',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', "You're on the list");
    }

    #[Test]
    public function submit_valid_signup_creates_entity(): void
    {
        $client = self::createClient();
        $email = 'entity-test-' . Uuid::uuid7()->toString() . '@example.com';

        $client->request('POST', '/beta', [
            'email' => $email,
            'domain_count' => '10',
            'pain_point' => 'Too many DNS records',
            'source' => 'beta-page',
        ]);

        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $signup = $em->getRepository(BetaSignup::class)->findOneBy(['email' => $email]);

        self::assertNotNull($signup);
        self::assertSame(10, $signup->domainCount);
        self::assertSame('Too many DNS records', $signup->painPoint);
        self::assertSame('beta-page', $signup->source);
    }

    #[Test]
    public function submit_invalid_email_shows_error(): void
    {
        $client = self::createClient();
        $client->request('POST', '/beta', [
            'email' => 'not-an-email',
            'source' => 'beta-page',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function submit_empty_email_shows_error(): void
    {
        $client = self::createClient();
        $client->request('POST', '/beta', [
            'email' => '',
            'source' => 'beta-page',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function duplicate_email_shows_success_silently(): void
    {
        $client = self::createClient();
        $email = 'duplicate-' . Uuid::uuid7()->toString() . '@example.com';

        $client->request('POST', '/beta', [
            'email' => $email,
            'source' => 'beta-page',
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/beta', [
            'email' => $email,
            'source' => 'beta-page',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', "You're on the list");
    }

    #[Test]
    public function confirm_with_valid_token(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $token = bin2hex(random_bytes(32));
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'confirm-' . Uuid::uuid7()->toString() . '@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: $token,
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $client->request('GET', '/beta/confirm/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "You're confirmed");

        $em->clear();
        $refreshed = $em->find(BetaSignup::class, $signup->id);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->confirmedAt);
    }

    #[Test]
    public function confirm_with_invalid_token_returns_404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/beta/confirm/nonexistenttoken');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function confirm_already_confirmed_does_not_change_date(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $token = bin2hex(random_bytes(32));
        $confirmedAt = new \DateTimeImmutable('2026-03-20 10:00:00');
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'already-confirmed-' . Uuid::uuid7()->toString() . '@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: $token,
        );
        $signup->popEvents();
        $signup->confirm($confirmedAt);
        $em->persist($signup);
        $em->flush();

        $client->request('GET', '/beta/confirm/' . $token);

        self::assertResponseIsSuccessful();

        $em->clear();
        $refreshed = $em->find(BetaSignup::class, $signup->id);
        self::assertNotNull($refreshed);
        self::assertSame(
            $confirmedAt->format('Y-m-d H:i:s'),
            $refreshed->confirmedAt->format('Y-m-d H:i:s'),
        );
    }
}

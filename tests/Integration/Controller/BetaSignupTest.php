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
    public function betaRouteRedirectsToHomePermanently(): void
    {
        $client = self::createClient();
        $client->request('GET', '/beta');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/');
    }

    #[Test]
    public function betaRoutePostAlsoRedirects(): void
    {
        $client = self::createClient();
        $client->request('POST', '/beta', [
            'email' => 'whatever@example.com',
        ]);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/');
    }

    #[Test]
    public function confirmWithValidTokenRedirectsToLogin(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $token = bin2hex(random_bytes(32));
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'confirm-'.Uuid::uuid7()->toString().'@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: $token,
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $client->request('GET', '/beta/confirm/'.$token);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function confirmWithValidTokenSetsConfirmedAt(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $token = bin2hex(random_bytes(32));
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'confirm-set-'.Uuid::uuid7()->toString().'@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: $token,
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $client->request('GET', '/beta/confirm/'.$token);

        $em->clear();
        $refreshed = $em->find(BetaSignup::class, $signup->id);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->confirmedAt);
    }

    #[Test]
    public function confirmWithValidTokenShowsFlashMessage(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $token = bin2hex(random_bytes(32));
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'confirm-flash-'.Uuid::uuid7()->toString().'@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: $token,
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $client->request('GET', '/beta/confirm/'.$token);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'confirmed');
    }

    #[Test]
    public function confirmAlreadyConfirmedRedirectsToLogin(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $token = bin2hex(random_bytes(32));
        $confirmedAt = new \DateTimeImmutable('2026-03-20 10:00:00');
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'already-confirmed-'.Uuid::uuid7()->toString().'@example.com',
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

        $client->request('GET', '/beta/confirm/'.$token);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');

        $em->clear();
        $refreshed = $em->find(BetaSignup::class, $signup->id);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->confirmedAt);
        self::assertSame(
            $confirmedAt->format('Y-m-d H:i:s'),
            $refreshed->confirmedAt->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function confirmWithInvalidTokenReturns404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/beta/confirm/nonexistenttoken');

        self::assertResponseStatusCodeSame(404);
    }
}

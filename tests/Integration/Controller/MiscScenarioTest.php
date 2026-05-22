<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DomainHealthSnapshot;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Catch-all for routes that don't fit a feature-specific test file but
 * still need scenario coverage beyond the generic smoke pass:
 * /app/domain-taken/notify-admin (POST), /health/{hash} (public).
 */
final class MiscScenarioTest extends WebTestCase
{
    #[Test]
    public function domainTakenNotifyAdminRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/app/domain-taken/notify-admin', ['domain' => 'foo.example']);

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function domainTakenNotifyAdminWithoutDomainRedirectsToDashboard(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('POST', '/app/domain-taken/notify-admin', ['domain' => '']);

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function domainTakenNotifyAdminForOwnedDomainFails(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        // Pinging admins about a domain we already own is rate-limited via
        // DomainNotTaken — verify we don't 500 out.
        $client->loginUser($persona->user);
        $client->request('POST', '/app/domain-taken/notify-admin', [
            'domain' => $persona->domain->domain,
        ]);

        self::assertResponseRedirects();
    }

    #[Test]
    public function publicDomainHealthReturns404ForUnknownHash(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/this-hash-does-not-exist-anywhere');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function publicDomainHealthRendersForValidHash(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $hash = bin2hex(random_bytes(16));
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 100,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: $hash,
        ));
        $em->flush();

        $client->request('GET', '/health/'.$hash);

        self::assertResponseIsSuccessful();
    }
}

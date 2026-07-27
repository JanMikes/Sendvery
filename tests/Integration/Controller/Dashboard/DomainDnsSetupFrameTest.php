<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Dashboard;

use App\Entity\DnsCheckResult;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The turbo-frame endpoint the guided DNS setup surface polls while a domain's
 * first check is still running. It exists so the page settles into its real
 * state by itself — a user who added a domain and waited previously had to
 * reload by hand, and the page then flipped straight to red.
 */
final class DomainDnsSetupFrameTest extends WebTestCase
{
    #[Test]
    public function itRendersTheSameSurfaceTheFullPageRenders(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persistDmarcCheck($em, $persona->domain->id);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s/dns-setup', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('turbo-frame#domain-dns-setup [data-testid="guided-dns-setup"]'),
            'The response must carry the matching frame so Turbo can swap it in place.',
        );
        self::assertSame(
            'full',
            (string) $crawler->filter('[data-testid="guided-dns-setup"]')->attr('data-setup-mode'),
            'Full is the default render mode.',
        );
    }

    #[Test]
    public function itHonoursTheCompactRenderModeTheDomainPageAsksFor(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s/dns-setup?mode=compact', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertSame('compact', (string) $crawler->filter('[data-testid="guided-dns-setup"]')->attr('data-setup-mode'));
    }

    #[Test]
    public function anUnrecognisedModeFallsBackToTheFullRenderRatherThanBreaking(): void
    {
        // The mode arrives in a URL the user can edit; an unknown value must not
        // reach a template branch.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s/dns-setup?mode=whatever', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertSame('full', (string) $crawler->filter('[data-testid="guided-dns-setup"]')->attr('data-setup-mode'));
    }

    #[Test]
    public function anotherTeamsDomainIsNotFound(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->onboardedOwner();
        $outsider = $fixtures->onboardedOwner();
        assert(null !== $owner->domain);

        $client->loginUser($outsider->user);
        $client->request('GET', sprintf('/app/domains/%s/dns-setup', $owner->domain->id->toString()));

        self::assertResponseStatusCodeSame(404);
    }

    private function persistDmarcCheck(EntityManagerInterface $em, \Ramsey\Uuid\UuidInterface $domainId): void
    {
        $domain = $em->find(\App\Entity\MonitoredDomain::class, $domainId);
        assert(null !== $domain);

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}

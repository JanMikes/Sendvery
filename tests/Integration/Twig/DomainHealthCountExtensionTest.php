<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Twig\DomainHealthCountExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Drives the {@see DomainHealthCountExtension} through realistic scenarios so
 * the sidebar's red "N unverified" badge on the Domains link always reflects
 * the right team scope and only counts genuinely-unverified domains.
 *
 * Tests assert on `getGlobals()` directly (not rendered HTML) — the template
 * branching is trivial and the high-value contract is "which count comes out
 * of the extension for which fixtures."
 */
final class DomainHealthCountExtensionTest extends WebTestCase
{
    #[Test]
    public function noUserReturnsZeroCount(): void
    {
        self::createClient();

        $globals = $this->getService(DomainHealthCountExtension::class)->getGlobals();

        self::assertSame(0, $globals['unverified_domain_count']);
    }

    #[Test]
    public function noTeamMembershipReturnsZeroCount(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->notOnboarded()->build();
        $em = $this->getService(EntityManagerInterface::class);
        $em->remove($persona->membership);
        $em->flush();
        $client->loginUser($persona->user);

        $globals = $this->getService(DomainHealthCountExtension::class)->getGlobals();

        self::assertSame(0, $globals['unverified_domain_count']);
    }

    #[Test]
    public function zeroUnverifiedDomainsReturnsZeroCount(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $client->loginUser($persona->user);

        $globals = $this->getService(DomainHealthCountExtension::class)->getGlobals();

        self::assertSame(0, $globals['unverified_domain_count']);
    }

    #[Test]
    public function oneUnverifiedDomainReturnsOne(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(DomainHealthCountExtension::class)->getGlobals();

        self::assertSame(1, $globals['unverified_domain_count']);
    }

    #[Test]
    public function tenUnverifiedDomainsReturnsTen(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        for ($i = 0; $i < 10; ++$i) {
            $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        }
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(DomainHealthCountExtension::class)->getGlobals();

        self::assertSame(10, $globals['unverified_domain_count']);
    }

    #[Test]
    public function verifiedDomainsDoNotCount(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        $verifiedAt = new \DateTimeImmutable();
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: $verifiedAt);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: $verifiedAt);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(DomainHealthCountExtension::class)->getGlobals();

        self::assertSame(1, $globals['unverified_domain_count']);
    }

    private function persistDomain(
        EntityManagerInterface $em,
        Team $team,
        ?\DateTimeImmutable $dmarcVerifiedAt,
    ): MonitoredDomain {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'd-'.$id->toString().'.example',
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $dmarcVerifiedAt,
        );
        $domain->popEvents();
        $em->persist($domain);

        return $domain;
    }
}

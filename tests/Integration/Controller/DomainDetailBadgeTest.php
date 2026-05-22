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
 * Covers the at-a-glance SPF/DKIM/DMARC/MX badge row added to the domain
 * detail header (TASK-013) plus the matching anchor IDs on the per-domain
 * Health page that the badges deep-link into.
 */
final class DomainDetailBadgeTest extends WebTestCase
{
    #[Test]
    public function badgeRowRendersForOnboardedOwner(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // SPF badge appears as an <a> with badge-success or badge-error class.
        self::assertMatchesRegularExpression(
            '/badge[^"]*badge-(success|error)[^"]*"[^>]*>SPF</',
            $body,
        );
    }

    #[Test]
    public function badgeRowRendersSuccessForFullyVerifiedDomain(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        $persona->domain->spfVerifiedAt = $verifiedAt;
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>SPF</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>DKIM</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>DMARC</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>MX</', $body);
    }

    #[Test]
    public function badgeRowRendersErrorForUnverifiedSpf(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        // SPF intentionally left null — only DKIM + DMARC verified.
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-error[^"]*"[^>]*>SPF</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>DKIM</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>DMARC</', $body);
    }

    #[Test]
    public function badgeRowRendersGhostMxWhenNoSnapshot(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-ghost[^"]*"[^>]*>MX</', $body);
    }

    #[Test]
    public function badgeRowRendersMxWarningForMidScore(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'C',
            score: 65,
            spfScore: 60,
            dkimScore: 60,
            dmarcScore: 60,
            mxScore: 65,
            blacklistScore: 70,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-warning[^"]*"[^>]*>MX</', $body);
    }

    #[Test]
    public function badgeRowRendersMxErrorForLowScore(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'F',
            score: 25,
            spfScore: 20,
            dkimScore: 20,
            dmarcScore: 20,
            mxScore: 30,
            blacklistScore: 40,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-error[^"]*"[^>]*>MX</', $body);
    }

    #[Test]
    public function badgeRowBadgesDeepLinkToHealthPage(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('#health-spf', $body);
        self::assertStringContainsString('#health-dkim', $body);
        self::assertStringContainsString('#health-dmarc', $body);
        self::assertStringContainsString('#health-mx', $body);
    }

    #[Test]
    public function healthPageHasAnchorIdsForDeepLinks(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Anchors on category rows + score/trend cards only render in the
        // "has snapshot" branch of the health template — persist a snapshot
        // so we hit that branch.
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s/health', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="health-spf"', $body);
        self::assertStringContainsString('id="health-dkim"', $body);
        self::assertStringContainsString('id="health-dmarc"', $body);
        self::assertStringContainsString('id="health-mx"', $body);
        self::assertStringContainsString('id="health-score"', $body);
    }

    #[Test]
    public function domainWithNoSnapshotAndNotVerifiedShowsAllErrorBadges(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        // Extra domain — all verified_at null by default, no snapshot.
        $extra = $fixtures->addExtraDomain($persona->team, 'unverified-extra');

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $extra->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-error[^"]*"[^>]*>SPF</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-error[^"]*"[^>]*>DKIM</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-error[^"]*"[^>]*>DMARC</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-ghost[^"]*"[^>]*>MX</', $body);
    }

    #[Test]
    public function domainWithVerifiedDnsButNoSnapshotShowsThreeSuccessAndGhostMx(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        $persona->domain->spfVerifiedAt = $verifiedAt;
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>SPF</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>DKIM</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-success[^"]*"[^>]*>DMARC</', $body);
        self::assertMatchesRegularExpression('/badge[^"]*badge-ghost[^"]*"[^>]*>MX</', $body);
    }
}

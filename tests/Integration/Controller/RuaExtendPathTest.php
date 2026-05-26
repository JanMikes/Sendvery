<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * TASK-167 — DMARC RUA "extend" path UX.
 *
 * Covers the extend option in the domain setup checklist's RUA destination
 * row when the user's DMARC record points at an external address. Verifies
 * that Sendvery offers to extend (add alongside) rather than only replace,
 * surfaces the authorization record warning, and warns about the RFC 7489
 * 2-address practical limit.
 */
final class RuaExtendPathTest extends WebTestCase
{
    #[Test]
    public function extendOptionRendersWhenDmarcPointsAtExternalAddress(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDmarcCheckResult($em, $persona->domain, 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $extendOption = $crawler->filter('[data-testid="rua-extend-option"]');
        self::assertCount(1, $extendOption, 'The extend option must render when rua= points at an external address so the user knows they can add Sendvery alongside their existing address.');

        $extendRecord = $crawler->filter('[data-testid="rua-extend-record"]');
        self::assertCount(1, $extendRecord, 'The extended DMARC record must be visible for copy-to-clipboard.');
        self::assertStringContainsString('reports@sendvery.test', $extendRecord->text(), 'The extended record must include the Sendvery report address.');
        self::assertStringContainsString('dmarc@example.com', $extendRecord->text(), 'The extended record must preserve the user\'s existing rua address.');
    }

    #[Test]
    public function copyButtonRendersNextToExtendRecord(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDmarcCheckResult($em, $persona->domain, 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $copyBtn = $crawler->filter('[data-testid="rua-extend-copy"]');
        self::assertCount(1, $copyBtn, 'A copy button must render next to the extended record so the user can paste it into their DNS provider.');
    }

    #[Test]
    public function authorizationRecordWarningRendersWithExtendOption(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDmarcCheckResult($em, $persona->domain, 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $authWarning = $crawler->filter('[data-testid="rua-authorization-warning"]');
        self::assertCount(1, $authWarning, 'The authorization record warning must render alongside the extend option — without it, ISPs may silently drop reports sent to Sendvery.');
        self::assertStringContainsString('authorization record', $authWarning->text());
    }

    #[Test]
    public function twoAddressWarningRendersWhenRuaAlreadyHasTwoAddresses(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDmarcCheckResult(
            $em,
            $persona->domain,
            'v=DMARC1; p=reject; rua=mailto:dmarc@example.com,mailto:reports@monitoring.com',
        );

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $limitWarning = $crawler->filter('[data-testid="rua-address-limit-warning"]');
        self::assertCount(1, $limitWarning, 'The 2-address limit warning must render when the existing rua already has 2 addresses — RFC 7489 lets receivers cap delivery to 2.');
        self::assertStringContainsString('2 addresses', $limitWarning->text());
    }

    #[Test]
    public function noTwoAddressWarningWhenRuaHasOnlyOneAddress(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDmarcCheckResult($em, $persona->domain, 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $limitWarning = $crawler->filter('[data-testid="rua-address-limit-warning"]');
        self::assertCount(0, $limitWarning, 'The 2-address warning must not render when the existing rua has only 1 address — adding Sendvery as a second is within the practical limit.');
    }

    #[Test]
    public function extendOptionHiddenWhenSendveryAlreadyInRua(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDmarcCheckResult($em, $persona->domain, 'v=DMARC1; p=reject; rua=mailto:reports@sendvery.com');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $extendOption = $crawler->filter('[data-testid="rua-extend-option"]');
        self::assertCount(0, $extendOption, 'The extend option must not render when Sendvery is already in the rua — the "already configured" case shows green status instead.');
    }

    #[Test]
    public function extendOptionHiddenWhenNoDmarcRecordExists(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $extendOption = $crawler->filter('[data-testid="rua-extend-option"]');
        self::assertCount(0, $extendOption, 'The extend option must not render when no DMARC record exists — there is nothing to extend.');
    }

    private function insertDmarcCheckResult(
        EntityManagerInterface $em,
        \App\Entity\MonitoredDomain $domain,
        string $rawRecord,
    ): void {
        $now = new \DateTimeImmutable();
        $domain->dmarcVerifiedAt = $now;
        $domain->spfVerifiedAt = $now;
        $domain->dkimVerifiedAt = $now;

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: $now,
            rawRecord: $rawRecord,
            isValid: true,
            issues: [],
            details: [
                'policy' => 'reject',
                'rua_addresses' => ['dmarc@example.com'],
            ],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);

        $snapshot = new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 90,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 90,
            blacklistScore: 100,
            checkedAt: $now,
        );
        $em->persist($snapshot);
        $em->flush();
    }
}

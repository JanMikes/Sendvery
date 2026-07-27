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
 * DMARC RUA "extend" path UX.
 *
 * When a domain already publishes a `_dmarc` record pointing somewhere else,
 * Sendvery offers to be ADDED alongside the existing address rather than only to
 * replace it — plus the two consequences of doing so: reports only arrive once an
 * authorization record exists, and RFC 7489 lets receivers cap delivery at two
 * addresses.
 *
 * The presentation moved from a sub-card inside the old five-row setup checklist
 * to the report-delivery step of the guided setup surface, so the hooks are
 * `guided-dns-record*`. The two cautions kept their original identifiers because
 * the facts they state did not change.
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

        // Editing, not adding: a `_dmarc` record already exists, and telling the
        // user to add a second one would break DMARC for the domain.
        self::assertStringContainsString(
            'Edit the existing record',
            $crawler->filter('[data-testid="guided-dns-record-action"]')->text(),
        );

        $extendRecord = $crawler->filter('[data-testid="guided-dns-record-final"]');
        self::assertCount(1, $extendRecord, 'The extended DMARC record must be visible for copy-to-clipboard.');
        self::assertStringContainsString('reports@sendvery.test', $extendRecord->text(), 'The extended record must include the Sendvery report address.');
        self::assertStringContainsString('dmarc@example.com', $extendRecord->text(), 'The extended record must preserve the user\'s existing rua address.');

        // And the value they are replacing is shown next to it, so the change is
        // visible rather than something they have to trust.
        self::assertStringContainsString(
            'dmarc@example.com',
            $crawler->filter('[data-testid="guided-dns-record-current"]')->text(),
        );
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

        $copyBtn = $crawler->filter('[data-testid="guided-dns-record-copy"]');
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
        self::assertStringContainsStringIgnoringCase('authorization', $authWarning->text());
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

        // Nothing to extend: reports already reach us, so the delivery step is
        // finished and carries neither a record nor its cautions.
        self::assertCount(
            0,
            $crawler->filter('[data-testid="guided-setup-step-delivery"]'),
            'A domain whose reports already reach Sendvery must not be asked to change its DMARC record.',
        );
        self::assertCount(0, $crawler->filter('[data-testid="rua-authorization-warning"]'));
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

        // With no record to extend there is no address list to overflow, so the
        // RFC 7489 caution would be noise.
        self::assertCount(
            0,
            $crawler->filter('[data-testid="rua-address-limit-warning"]'),
            'The address-limit caution belongs to the extend path only.',
        );
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

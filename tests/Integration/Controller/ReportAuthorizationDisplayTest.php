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
 * TASK-168 — RFC 7489 _report._dmarc authorization record awareness.
 *
 * Covers the authorization record status display in the domain setup
 * checklist. When rua= includes Sendvery's address on a different domain,
 * ISPs require a TXT record at {domain}._report._dmarc.{sendvery-host}
 * to authorize cross-domain report delivery. These tests verify the
 * detection result is surfaced correctly.
 */
final class ReportAuthorizationDisplayTest extends WebTestCase
{
    #[Test]
    public function authorizationConfiguredRendersGreenWhenRecordFound(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertCheckedDomain($em, $persona->domain, reportAuthorizationFound: true);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $authRow = $crawler->filter('[data-testid="report-authorization-row"]');
        self::assertCount(1, $authRow, 'The authorization row must render when the check has been performed.');

        $configured = $crawler->filter('[data-testid="report-authorization-configured"]');
        self::assertCount(1, $configured, 'The authorization row must show the configured state when the TXT record was found.');
        self::assertStringContainsString('Authorization record published', $configured->text());
    }

    #[Test]
    public function authorizationMissingRendersWarningWithExactRecord(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertCheckedDomain($em, $persona->domain, reportAuthorizationFound: false);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $missing = $crawler->filter('[data-testid="report-authorization-missing"]');
        self::assertCount(1, $missing, 'The authorization row must show the missing state with a warning when the TXT record is absent.');
        self::assertStringContainsString('Missing', $missing->text());

        $record = $crawler->filter('[data-testid="report-authorization-record"]');
        self::assertCount(1, $record, 'The exact TXT record to publish must be shown so the operator can copy it.');
        $recordText = $record->text();
        self::assertStringContainsString($persona->domain->domain, $recordText, 'The record must include the monitored domain name.');
        self::assertStringContainsString('_report._dmarc', $recordText, 'The record must include the _report._dmarc prefix.');
        self::assertStringContainsString('v=DMARC1', $recordText, 'The record must contain the v=DMARC1 value.');
    }

    #[Test]
    public function authorizationRowHiddenWhenCheckNotApplicable(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertCheckedDomain($em, $persona->domain, reportAuthorizationFound: null);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $authRow = $crawler->filter('[data-testid="report-authorization-row"]');
        self::assertCount(0, $authRow, 'The authorization row must be hidden when the check does not apply — e.g. when rua points to the same domain or when no rua includes Sendvery.');
    }

    #[Test]
    public function authorizationRowHiddenBeforeFirstDnsCheck(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $authRow = $crawler->filter('[data-testid="report-authorization-row"]');
        self::assertCount(0, $authRow, 'The authorization row must not render before any DNS check has been recorded.');
    }

    #[Test]
    public function authorizationRecordShowsReportAddressDomainFromEnv(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertCheckedDomain($em, $persona->domain, reportAuthorizationFound: false);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $record = $crawler->filter('[data-testid="report-authorization-record"]');
        self::assertStringContainsString('sendvery.test', $record->text(), 'The authorization record must use the report address domain from the environment (sendvery.test in tests) — not hardcoded sendvery.com — so self-hosters see the correct domain.');
    }

    private function insertCheckedDomain(
        EntityManagerInterface $em,
        \App\Entity\MonitoredDomain $domain,
        ?bool $reportAuthorizationFound,
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
            rawRecord: 'v=DMARC1; p=reject; rua=mailto:reports@sendvery.test',
            isValid: true,
            issues: [],
            details: [
                'policy' => 'reject',
                'rua_addresses' => ['reports@sendvery.test'],
                'report_authorization_found' => $reportAuthorizationFound,
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

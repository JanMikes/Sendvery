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

final class ReportAuthorizationDisplayTest extends WebTestCase
{
    #[Test]
    public function authorizationConfiguredRendersGreenWithAutomationMessage(): void
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
        self::assertStringContainsString('published automatically', $configured->text(), 'When Cloudflare is configured (SaaS mode), the message must indicate automatic publishing.');
    }

    #[Test]
    public function authorizationMissingShowsProvisioningInSaasMode(): void
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

        $provisioning = $crawler->filter('[data-testid="report-authorization-provisioning"]');
        self::assertCount(1, $provisioning, 'When Cloudflare is configured and the record is missing, the provisioning message must appear instead of manual instructions.');
        self::assertStringContainsString('being provisioned', $provisioning->text());

        $manualRecord = $crawler->filter('[data-testid="report-authorization-record"]');
        self::assertCount(0, $manualRecord, 'SaaS mode must not show manual TXT record instructions.');
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

        $this->insertCheckedDomain($em, $persona->domain, reportAuthorizationFound: true);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $configured = $crawler->filter('[data-testid="report-authorization-configured"]');
        self::assertStringContainsString('Sendvery', $configured->text(), 'The authorization record must reference Sendvery so the user understands what service is handling reports.');
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

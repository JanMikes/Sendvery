<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * End-to-end coverage for the "By sender" grouping pane added to
 * /app/reports/{id}. Each test sets up a persona with at least one
 * report and asserts on the rendered HTML.
 */
final class ReportDetailSenderGroupsTest extends WebTestCase
{
    /**
     * @return array{persona: Persona, domain: MonitoredDomain, report: DmarcReport}
     */
    private function setupPersonaWithReport(string $prefix = 'sender-groups'): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($prefix)
            ->teamName('Sender Groups')
            ->withDomain('senders.example')
            ->build();
        assert(null !== $persona->domain);
        $domain = $persona->domain;

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2026-05-01'),
            dateRangeEnd: new \DateTimeImmutable('2026-05-02'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);
        $em->flush();

        return ['persona' => $persona, 'domain' => $domain, 'report' => $report];
    }

    private function persistRecord(
        DmarcReport $report,
        string $sourceIp,
        int $count,
        AuthResult $dkim,
        AuthResult $spf,
        Disposition $disposition = Disposition::None,
        ?string $resolvedHostname = null,
        ?string $resolvedOrg = null,
    ): void {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: $disposition,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $report->monitoredDomain->domain,
            resolvedHostname: $resolvedHostname,
            resolvedOrg: $resolvedOrg,
        ));
    }

    private function persistKnownSender(MonitoredDomain $domain, string $sourceIp, bool $isAuthorized): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable();
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $sourceIp,
            firstSeenAt: $now,
            lastSeenAt: $now,
            totalMessages: 0,
            passRate: 0.0,
            isAuthorized: $isAuthorized,
        ));
    }

    public function testPageReturns200(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-200');
        $this->persistRecord($ctx['report'], '1.2.3.4', 5, AuthResult::Pass, AuthResult::Pass);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
    }

    public function testBySenderHeadingIsPresent(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-heading');
        $this->persistRecord($ctx['report'], '1.2.3.4', 5, AuthResult::Pass, AuthResult::Pass);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', 'By sender');
    }

    public function testShowsResolvedOrgAsGroupLabel(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-org');
        $this->persistRecord(
            $ctx['report'],
            '1.2.3.4',
            10,
            AuthResult::Pass,
            AuthResult::Pass,
            resolvedOrg: 'google.com',
        );
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', 'google.com');
    }

    public function testShowsDkimPassRatePercentage(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-dkim');
        // 5 pass + 5 fail = 50%
        $this->persistRecord($ctx['report'], '1.1.1.1', 5, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'half.example');
        $this->persistRecord($ctx['report'], '1.1.1.2', 5, AuthResult::Fail, AuthResult::Fail, resolvedOrg: 'half.example');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', 'DKIM 50%');
    }

    public function testShowsRejectDispositionBadge(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-reject');
        $this->persistRecord(
            $ctx['report'],
            '1.2.3.4',
            7,
            AuthResult::Fail,
            AuthResult::Fail,
            Disposition::Reject,
            resolvedOrg: 'rejected.example',
        );
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', '7 reject');
    }

    public function testShowsAuthorizedBadgeForKnownAuthorizedSender(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-authorized');
        $this->persistRecord($ctx['report'], '9.9.9.9', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'mailchimp.com');
        $this->persistKnownSender($ctx['domain'], '9.9.9.9', isAuthorized: true);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', 'Authorized');
    }

    public function testShowsUnauthorizedBadgeForKnownUnauthorizedSender(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-unauth');
        $this->persistRecord($ctx['report'], '8.8.8.8', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'sketchy.example');
        $this->persistKnownSender($ctx['domain'], '8.8.8.8', isAuthorized: false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', 'Unauthorized');
    }

    public function testRawRecordsTableIsBehindDetailsToggle(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-toggle');
        $this->persistRecord($ctx['report'], '1.2.3.4', 5, AuthResult::Pass, AuthResult::Pass);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains('body', 'Show raw records');
    }
}

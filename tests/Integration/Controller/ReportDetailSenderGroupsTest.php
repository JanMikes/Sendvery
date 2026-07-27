<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\User;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
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

    /**
     * @param ?User $rejectedBy when given, the sender is not merely unauthorized
     *                          but has been REVIEWED and rejected by a human —
     *                          the distinction SenderReviewState draws between
     *                          NeedsReview and NotAuthorized, which it reads off
     *                          `updatedAt`
     */
    private function persistKnownSender(MonitoredDomain $domain, string $sourceIp, bool $isAuthorized, ?User $rejectedBy = null): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable();
        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $sourceIp,
            firstSeenAt: $now,
            lastSeenAt: $now,
            totalMessages: 0,
            passRate: 0.0,
            isAuthorized: $isAuthorized,
        );

        if (null !== $rejectedBy) {
            $sender->markUnknown($rejectedBy, $now);
        }

        $em->persist($sender);
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

    public function testSenderNobodyHasDecidedAboutAsksForReviewRatherThanAccusing(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-unauth');
        $this->persistRecord($ctx['report'], '8.8.8.8', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'sketchy.example');
        // Not authorized, but never reviewed either — the state every newly
        // discovered sender starts in.
        $this->persistKnownSender($ctx['domain'], '8.8.8.8', isAuthorized: false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains(
            'body',
            'Needs review',
            'A sender nobody has decided about is a pending question, not a finding. '
            .'Calling it "Unauthorized" here while the Sender Inventory calls it "Needs review" '
            .'gave the same server two contradictory verdicts on one product.',
        );
    }

    public function testSenderTheUserReviewedAndRejectedStaysFlaggedAsNotAuthorized(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-rejected');
        $this->persistRecord($ctx['report'], '8.8.4.4', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'sketchy.example');
        $this->persistKnownSender($ctx['domain'], '8.8.4.4', isAuthorized: false, rejectedBy: $ctx['persona']->user);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertSelectorTextContains(
            'body',
            'Not authorized',
            'A sender the user actively rejected must read differently from one merely awaiting '
            .'a decision — otherwise reviewing senders has no visible effect.',
        );
    }

    private function persistIdentity(string $sourceIp, string $hostname, string $registrableDomain, SenderRole $role): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: $sourceIp,
            resolvedAt: new \DateTimeImmutable(),
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: null,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * The incident in one page: three regional nodes of one security gateway
     * used to be three "senders", each failing SPF, none explained. They are
     * now one line that says what it is.
     */
    public function testAGatewayIsOneNamedSenderAndSaysWhatItIs(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-gateway');

        $nodes = [
            '52.212.19.177' => 'eu.cloud-sec-av.com',
            '15.222.110.90' => 'ca.cloud-sec-av.com',
            '35.174.145.124' => 'us.cloud-sec-av.com',
        ];

        foreach ($nodes as $ip => $hostname) {
            $this->persistIdentity($ip, $hostname, 'cloud-sec-av.com', SenderRole::Forwarder);
            $this->persistRecord($ctx['report'], $ip, 3, AuthResult::Pass, AuthResult::Fail);
        }

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $crawler = $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();

        $roles = $crawler->filter('[data-testid="sender-group-role"]');
        self::assertCount(1, $roles, 'One gateway, one row — not one row per continent.');
        self::assertSame(SenderRole::Forwarder->label(), $roles->text());
        self::assertSelectorTextContains('body', 'cloud-sec-av.com');
    }

    /**
     * The explanation block is where a reader decides whether to panic. A
     * forwarder there needs to be named as one — its SPF failure is what
     * forwarding does, not a misconfiguration to chase.
     */
    public function testTheFailureExplanationSaysWhenTheSourceIsAForwarder(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-forwarder-record');

        // Both mechanisms fail because this gateway rewrites links in the body,
        // which breaks the DKIM signature as well as SPF — the signature that
        // a naive heuristic reads as spoofing.
        $this->persistIdentity('15.222.110.90', 'ca.cloud-sec-av.com', 'cloud-sec-av.com', SenderRole::Forwarder);
        $this->persistRecord($ctx['report'], '15.222.110.90', 2, AuthResult::Fail, AuthResult::Fail);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $crawler = $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="record-sender-role"]')->count(),
        );
        self::assertSelectorTextContains('body', SenderRole::Forwarder->label());
    }

    /**
     * Most addresses are simply not classified, and saying "Unrecognised
     * sender" on every one of them would be noise dressed as information.
     */
    public function testAnUnclassifiedSenderCarriesNoRoleLabel(): void
    {
        $client = self::createClient();
        $ctx = $this->setupPersonaWithReport('detail-no-role');
        $this->persistRecord($ctx['report'], '198.51.100.150', 4, AuthResult::Fail, AuthResult::Fail);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();

        $client->loginUser($ctx['persona']->user);
        $crawler = $client->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="sender-group-role"]'));
        self::assertCount(0, $crawler->filter('[data-testid="record-sender-role"]'));
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

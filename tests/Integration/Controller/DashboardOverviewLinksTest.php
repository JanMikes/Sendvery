<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-032's overview-page link wiring:
 *
 * - Banner health-summary counts (healthy / attention / unverified) wrap as
 *   anchors to /app/domains?status=…
 * - Four of the five stat cards wrap as anchors (Monitored Domains, Reports,
 *   DMARC Pass Rate, plus the existing Unread Alerts + Reports this month).
 * - Total Messages is intentionally NOT linked (no per-message drill-down).
 *
 * The seed must produce all three banner counts > 0 simultaneously. The
 * HealthSummaryResolver pegs `unverified` to 1 only when the headline domain
 * (most-recently-created) is the unverified one, so the unverified domain is
 * the LAST domain we persist — which is why we build healthy first, then
 * attention, then unverified.
 */
final class DashboardOverviewLinksTest extends WebTestCase
{
    private function createClientWithAllThreeStates(): KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);

        // Persona without a domain — we build all three domains explicitly with
        // controlled createdAt so GetDomainVerificationStatus's
        // ORDER BY created_at DESC LIMIT 1 deterministically picks the
        // unverified one (most-recently-created), yielding Critical severity →
        // domainsUnverifiedCount = 1 in HealthSummaryResolver.
        $persona = $fixtures->persona()
            ->emailPrefix('overview-'.$suffix)
            ->teamName('Overview Test '.$suffix)
            ->plan('personal')
            ->withoutDomain()
            ->build();

        // Healthy: verified + 100% pass. Oldest created_at. TASK-098: also
        // mark SPF/DKIM verified and seed a DNS snapshot with a passing MX
        // score — the unified DomainHealthClassifier needs all four protocol
        // signals present to declare Healthy. Without these, the domain would
        // fall back to Attention (verified but missing DNS data) and the
        // healthy anchor would never render.
        $healthyDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: 'healthy-'.$suffix.'.example',
            createdAt: new \DateTimeImmutable('-30 days'),
            dmarcPolicy: DmarcPolicy::Reject,
            spfVerifiedAt: new \DateTimeImmutable('-10 days'),
            dkimVerifiedAt: new \DateTimeImmutable('-10 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
            firstReportAt: new \DateTimeImmutable('-9 days'),
        );
        $healthyDomain->popEvents();
        $em->persist($healthyDomain);
        $this->persistReport($em, $healthyDomain, pass: 10, fail: 0);
        $this->persistHealthSnapshot($em, $healthyDomain);

        // Attention: verified + 30% pass. Middle created_at.
        $attentionDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: 'attention-'.$suffix.'.example',
            createdAt: new \DateTimeImmutable('-5 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-7 days'),
        );
        $attentionDomain->popEvents();
        $em->persist($attentionDomain);
        $this->persistReport($em, $attentionDomain, pass: 3, fail: 7);

        // Unverified: dmarcVerifiedAt = null + most-recently-created → the
        // domain GetDomainVerificationStatus picks as the headline.
        // DomainVerificationEvaluator's first branch ("never seen DMARC valid →
        // Critical") yields Critical severity, which is what
        // HealthSummaryResolver requires to bump domainsUnverifiedCount to 1.
        //
        // We give it a 100% pass-rate report so it's NOT also counted in
        // `attention` (passRate < 90). Otherwise healthy = total - attention -
        // unverified = 3 - 2 - 1 = 0 and the healthy chip never renders. The
        // verification state and the pass-rate count are independent axes.
        $unverifiedDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: 'unverified-'.$suffix.'.example',
            createdAt: new \DateTimeImmutable('-1 hour'),
        );
        $unverifiedDomain->popEvents();
        $em->persist($unverifiedDomain);
        $this->persistReport($em, $unverifiedDomain, pass: 10, fail: 0);

        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $unverifiedDomain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-30 minutes'),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));

        $em->flush();
        $client->loginUser($persona->user);

        return $client;
    }

    /**
     * Seeds a DNS-snapshot row so the TASK-098 `DomainHealthClassifier` can
     * see all four protocol scores (>= 80 for MX) — required for the domain
     * to land in the Healthy bucket of the unified counter.
     */
    private function persistHealthSnapshot(EntityManagerInterface $em, MonitoredDomain $domain): void
    {
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable('-1 hour'),
        ));
    }

    private function persistReport(EntityManagerInterface $em, MonitoredDomain $domain, int $pass, int $fail): void
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        if ($pass > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: $pass,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        if ($fail > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '5.6.7.8',
                count: $fail,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
    }

    #[Test]
    public function healthSummaryHealthyCountIsAnAnchor(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/domains?status=healthy"', $body);
    }

    #[Test]
    public function healthSummaryAttentionCountIsAnAnchor(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/domains?status=attention"', $body);
    }

    #[Test]
    public function healthSummaryUnverifiedCountIsAnAnchor(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/domains?status=unverified"', $body);
    }

    #[Test]
    public function monitoredDomainsStatCardLinksToDomainsPage(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/domains"', $body);
    }

    #[Test]
    public function reportsStatCardLinksToReportsPage(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/reports"', $body);
    }

    #[Test]
    public function dmarcPassRateStatCardLinksToLowPassRateReports(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/reports?pass_rate=low"', $body);
    }

    #[Test]
    public function totalMessagesStatCardIsNotLinked(): void
    {
        $client = $this->createClientWithAllThreeStates();

        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();

        // Find the StatCard h3 with "Total Messages" and walk every ancestor;
        // the IMMEDIATE wrapper of the .card must not be an <a>. Every other
        // stat card title is wrapped in <a class="block"><twig:StatCard></a>.
        $totalMessagesNodes = $crawler->filter('h3:contains("Total Messages")');
        self::assertCount(1, $totalMessagesNodes, 'Total Messages card must render exactly once');

        $card = $totalMessagesNodes->ancestors()->filter('div.card')->first();
        self::assertCount(1, $card, 'Total Messages h3 must live inside a .card wrapper');

        // The Total Messages .card's direct parent is the stat-card grid <div>,
        // not an anchor. Compare tag name on the parent node.
        $directParent = $card->getNode(0)?->parentNode;
        self::assertNotNull($directParent);
        self::assertNotSame('a', strtolower($directParent->nodeName), 'Total Messages card must not be wrapped in an <a> — there is no per-message drill-down view');
    }

    #[Test]
    public function unreadAlertsStatCardLinksToAlertsPage(): void
    {
        // Regression guard for the existing Unread Alerts → /app/alerts link.
        $client = $this->createClientWithAllThreeStates();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/alerts"', $body);
    }

    #[Test]
    public function reportsThisMonthStatCardLinksToBillingPage(): void
    {
        // The Reports this month card only renders when usage is >= 50%, so seed
        // a team_usage row at 600/1000 (60%) for the personal plan.
        $client = $this->createClientWithAllThreeStates();

        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        assert($connection instanceof \Doctrine\DBAL\Connection);

        // Find the seeded team via the membership of the logged-in user.
        $teamId = (string) $connection->fetchOne(
            'SELECT tm.team_id FROM team_membership tm
             JOIN "user" u ON u.id = tm.user_id
             WHERE u.email LIKE :pattern
             LIMIT 1',
            ['pattern' => 'overview-%'],
        );

        $connection->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :startsAt, :endsAt)',
            [
                'teamId' => $teamId,
                'count' => 600,
                'startsAt' => '2026-05-01 00:00:00',
                'endsAt' => '2026-06-01 00:00:00',
            ],
        );

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/settings/billing"', $body);
        self::assertStringContainsString('Reports this month', $body);
    }
}

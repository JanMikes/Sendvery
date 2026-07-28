<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
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
 * Integration coverage for TASK-032: the new ?status= filter chip row on
 * /app/domains. Three seeded domains exercise each filter branch end-to-end:
 *
 *   - healthy domain     → dmarcVerifiedAt set + all four DNS records passing
 *                          their latest check + 10/10 pass-rate=100 report
 *   - attention domain   → same DNS state, but a 3/10 pass-rate=30 report, so
 *                          the pass rate is the only thing holding it back
 *   - unverified domain  → dmarcVerifiedAt = null, no reports
 *
 * The first domain in PersonaBuilder is created with dmarcVerifiedAt = null by
 * default — we mutate it back to a verified timestamp where needed.
 *
 * The DNS check rows are not decoration. `DomainHealthClassifier` — which paints
 * the badge on each card — requires all four protocols configured before it will
 * say Healthy, so a domain with a verified DMARC record and no DNS check rows at
 * all is Attention, not Healthy. This fixture used to omit them and the test
 * still passed, because the `?status=` SQL filter only looked at DMARC-verified
 * plus pass rate: the chip and the badge were reading different rules, which is
 * precisely the defect W3 closes.
 */
final class DomainsFilterTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, healthyDomain: string, attentionDomain: string, unverifiedDomain: string}
     */
    private function createClientWithThreeDomains(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);

        // Persona seeds the unverified domain (dmarcVerifiedAt = null) first, so
        // GetDomainVerificationStatus (which picks the most-recently-created
        // domain) lands elsewhere — fine for this test since we only care that
        // the three rows render and filter correctly.
        $persona = $fixtures->persona()
            ->emailPrefix('filter-'.$suffix)
            ->teamName('Filter Test '.$suffix)
            ->withDomain('unverified-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);
        $unverifiedDomain = $persona->domain;

        $healthyDomain = $fixtures->addExtraDomain($persona->team, 'healthy-'.$suffix);
        $healthyDomain->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');
        $this->persistPassingDnsChecks($em, $healthyDomain);

        $attentionDomain = $fixtures->addExtraDomain($persona->team, 'attention-'.$suffix);
        $attentionDomain->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');
        $this->persistPassingDnsChecks($em, $attentionDomain);

        $this->persistReport($em, $healthyDomain, pass: 10, fail: 0);
        $this->persistReport($em, $attentionDomain, pass: 3, fail: 7);

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'healthyDomain' => $healthyDomain->domain,
            'attentionDomain' => $attentionDomain->domain,
            'unverifiedDomain' => $unverifiedDomain->domain,
        ];
    }

    /**
     * The newest `dns_check_result` per protocol, all passing — what a domain
     * whose records are genuinely in place looks like to the health classifier.
     */
    private function persistPassingDnsChecks(EntityManagerInterface $em, \App\Entity\MonitoredDomain $domain): void
    {
        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Dmarc, DnsCheckType::Mx] as $type) {
            $check = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: $type,
                checkedAt: new \DateTimeImmutable('-1 hour'),
                rawRecord: 'record',
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
                isFirstCheck: false,
            );
            $check->popEvents();
            $em->persist($check);
        }
    }

    private function persistReport(EntityManagerInterface $em, \App\Entity\MonitoredDomain $domain, int $pass, int $fail): void
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
    public function domainListWithoutFilterShowsAllDomains(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['healthyDomain'], $body);
        self::assertStringContainsString($data['attentionDomain'], $body);
        self::assertStringContainsString($data['unverifiedDomain'], $body);
    }

    #[Test]
    public function domainListFiltersByHealthyStatus(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=healthy');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['healthyDomain'], $body);
        self::assertStringNotContainsString($data['attentionDomain'], $body);
        self::assertStringNotContainsString($data['unverifiedDomain'], $body);
    }

    #[Test]
    public function domainListFiltersByAttentionStatus(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=attention');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['attentionDomain'], $body);
        self::assertStringNotContainsString($data['healthyDomain'], $body);
        self::assertStringNotContainsString($data['unverifiedDomain'], $body);
    }

    #[Test]
    public function domainListFiltersByUnverifiedStatus(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=unverified');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unverifiedDomain'], $body);
        self::assertStringNotContainsString($data['healthyDomain'], $body);
        self::assertStringNotContainsString($data['attentionDomain'], $body);
    }

    #[Test]
    public function domainListEmptyWithActiveFilterShowsClearFilterLink(): void
    {
        // Persona has a single unverified domain → ?status=healthy yields zero
        // results, but the team still has totalDomainCount > 0, exercising the
        // "no domains match" branch with its Clear filter CTA.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('empty-filter-'.substr(uniqid('', true), -6))
            ->teamName('Empty Filter Test')
            ->withDomain('only-unverified.example')
            ->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains?status=healthy');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('No domains match', $body);
        self::assertStringContainsString('href="/app/domains"', $body);
        self::assertStringContainsString('Clear filter', $body);
    }

    #[Test]
    public function domainListEmptyWithoutFilterShowsAddDomainCta(): void
    {
        // The OnboardingRedirectListener intercepts /app/* requests for users
        // without any domain — so the totalDomainCount == 0 branch is not
        // reachable through the live controller end-to-end. We render the
        // template directly, after pushing a Symfony Request onto the
        // RequestStack so base.html.twig's `app.request.uri` resolves.
        self::createClient();
        $requestStack = self::getContainer()->get(\Symfony\Component\HttpFoundation\RequestStack::class);
        assert($requestStack instanceof \Symfony\Component\HttpFoundation\RequestStack);
        $requestStack->push(\Symfony\Component\HttpFoundation\Request::create('/app/domains'));

        $twig = self::getContainer()->get('twig');
        assert($twig instanceof \Twig\Environment);

        $body = $twig->render('dashboard/domains.html.twig', [
            'domains' => [],
            'showTeamColumn' => false,
            'activeFilter' => null,
            'activeFilterRaw' => '',
            'totalDomainCount' => 0,
            'severityByDomain' => [],
            'dnsHealthByDomain' => [],
            'totalDnsCount' => 0,
            'healthyCount' => 0,
            'attentionCount' => 0,
            'awaitingCount' => 0,
        ]);

        self::assertStringContainsString('No domains yet', $body);
        self::assertStringContainsString('Add your first domain', $body);
        self::assertStringContainsString('href="/app/domains/add"', $body);
    }

    #[Test]
    public function invalidStatusQueryParamFallsBackToNoFilter(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=garbage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['healthyDomain'], $body);
        self::assertStringContainsString($data['attentionDomain'], $body);
        self::assertStringContainsString($data['unverifiedDomain'], $body);
    }

    #[Test]
    public function filterChipsRenderedOnDomainsPage(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('href="/app/domains"', $body);
        self::assertStringContainsString('href="/app/domains?status=healthy"', $body);
        self::assertStringContainsString('href="/app/domains?status=attention"', $body);
        self::assertStringContainsString('href="/app/domains?status=unverified"', $body);
        // TASK-130: new "Awaiting first check" chip absorbed from /app/dns-health.
        self::assertStringContainsString('href="/app/domains?status=unchecked"', $body);
    }
}

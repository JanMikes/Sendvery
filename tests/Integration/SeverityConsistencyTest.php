<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-098 regression net: the same domain renders the same severity on
 * `/app/domains` (list card), `/app/domains/{id}` (detail banner), and `/app`
 * (HealthSummary count). Each test in this class seeds ONE domain with a
 * specific mixed-signals shape and asserts the three surfaces agree on the
 * classification.
 *
 * The fixture domain is intentionally adversarial — it sits at the
 * intersection of the two pre-TASK-098 classifiers (verified DMARC + missing
 * SPF + 95% pass rate). Before unification the list card rendered green
 * (DMARC verified + pass rate ≥ 90) while the detail banner rendered yellow
 * (SPF missing). This test would have failed against the old code; the new
 * code makes them agree.
 */
final class SeverityConsistencyTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domainId: string, domainName: string}
     */
    private function seedVerifiedDomainMissingSpf(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);

        $persona = $fixtures->persona()
            ->emailPrefix('severity-'.$suffix)
            ->teamName('Severity Consistency '.$suffix)
            ->plan('personal')
            ->withoutDomain()
            ->build();

        // DMARC + DKIM verified, SPF NOT verified, 95% pass rate.
        // This is the intersection of the two pre-TASK-098 classifiers'
        // disagreement: the old list-page rule said Healthy (DMARC verified +
        // pass rate ≥ 90); the detail-page rule said Attention (SPF missing).
        // Under the new unified rule both surfaces must read Attention.
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: 'mixed-'.$suffix.'.example',
            createdAt: new \DateTimeImmutable('-30 days'),
            dmarcPolicy: DmarcPolicy::None,
            spfVerifiedAt: null,
            dkimVerifiedAt: new \DateTimeImmutable('-10 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
            firstReportAt: new \DateTimeImmutable('-9 days'),
        );
        $domain->popEvents();
        $em->persist($domain);

        // 19 pass / 1 fail → 95% pass rate, well above the 90 threshold.
        $this->persistReport($em, $domain, pass: 19, fail: 1);

        // SPF score null in the snapshot mirrors the SPF-not-verified state on
        // the domain — the classifier reads both signals as "SPF missing".
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'C',
            score: 70,
            spfScore: 0,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable('-1 hour'),
        ));

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $domain->id->toString(),
            'domainName' => $domain->domain,
        ];
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
    public function listCardForMixedSignalsDomainRendersAttention(): void
    {
        $data = $this->seedVerifiedDomainMissingSpf();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['domainName'], $body);
        // Attention tone on the DomainCard: warning border + warning glyph
        // chip. The success-green path must NOT appear for this domain.
        self::assertStringContainsString('border-l-warning', $body);
        self::assertStringContainsString('bg-warning/10', $body);
    }

    #[Test]
    public function detailBannerForMixedSignalsDomainRendersAttention(): void
    {
        $data = $this->seedVerifiedDomainMissingSpf();

        $data['client']->request('GET', '/app/domains/'.$data['domainId']);

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // DomainStatusBanner attention tone: warning bar + warning button.
        // The same domain renders the same severity tone as the list card.
        self::assertStringContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringContainsString('bg-warning', $body);
    }

    #[Test]
    public function overviewHealthSummaryCountsMixedSignalsDomainAsAttention(): void
    {
        $data = $this->seedVerifiedDomainMissingSpf();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // HealthSummary headline reports "1 domain needs attention" — the
        // mixed-signals domain lands in the same bucket as the list card +
        // detail banner. Locks the pre-TASK-098 bug where the count would
        // mis-attribute this domain to Healthy.
        self::assertStringContainsString('1 domain needs attention', $body);
    }
}

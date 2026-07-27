<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Query\GetDnsHealthOverview;
use App\Query\GetDomainOverview;
use App\Services\DomainHealthClassifier;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\DomainHealthFilter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A record that used to work and is broken NOW must keep the domain in triage.
 *
 * THE DEFECT THIS EXISTS FOR: `spf_verified_at` / `dkim_verified_at` /
 * `dmarc_verified_at` are only ever SET by `CheckDomainDnsHandler`, never
 * cleared. The health verdict read those columns, so a domain whose SPF record
 * was deleted a month ago still counted as "all four protocols configured" —
 * it earned the green "Fully healthy" chip on `/app/domains` AND was filtered
 * out of `/app` "Needs your attention" entirely. The alert email in the user's
 * inbox was the only place in the product that knew about the regression.
 *
 * The seeded shape is deliberately the worst case: everything else about the
 * domain looks excellent (DMARC verified, 99% pass rate, A-grade snapshot with a
 * healthy MX score), so nothing but the SPF check row can pull it out of Healthy.
 */
final class BrokenRecordStaysInTriageTest extends WebTestCase
{
    #[Test]
    public function theHealthQueriesCarryTheLatestCheckVerdictNotJustTheVerificationTimestamp(): void
    {
        $seeded = $this->seedDomainWithBrokenSpf();

        $dnsHealth = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($dnsHealth instanceof GetDnsHealthOverview);
        $overview = self::getContainer()->get(GetDomainOverview::class);
        assert($overview instanceof GetDomainOverview);

        $health = $dnsHealth->forDomain($seeded['domainId'], [$seeded['teamId']]);
        self::assertNotNull($health);
        self::assertFalse($health->spfCheckValid, 'The newest SPF check failed, and the DNS-health read model must say so.');
        self::assertTrue($health->dkimCheckValid);
        self::assertNotNull($health->spfVerifiedAt, 'The historical verification timestamp is deliberately left in place — it is simply not the health signal.');

        $row = $overview->forDomain($seeded['domainId'], [$seeded['teamId']]);
        self::assertNotNull($row);
        self::assertFalse($row->spfCheckValid, 'The list/summary read model must carry the same verdict as the DNS-health one.');
        self::assertTrue($row->mxCheckValid);
    }

    #[Test]
    public function aDomainWithACurrentlyFailingSpfCheckIsNotClassifiedHealthy(): void
    {
        $seeded = $this->seedDomainWithBrokenSpf();

        $overview = self::getContainer()->get(GetDomainOverview::class);
        assert($overview instanceof GetDomainOverview);
        $classifier = self::getContainer()->get(DomainHealthClassifier::class);
        assert($classifier instanceof DomainHealthClassifier);

        $row = $overview->forDomain($seeded['domainId'], [$seeded['teamId']]);
        self::assertNotNull($row);

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classifyOverview($row),
            'A 99% pass rate and an A-grade snapshot must not paper over a record that is broken right now.',
        );
    }

    #[Test]
    public function theDomainsListStopsCountingABrokenDomainAsFullyHealthy(): void
    {
        $seeded = $this->seedDomainWithBrokenSpf();

        $seeded['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $seeded['client']->getResponse()->getContent();
        self::assertStringContainsString($seeded['domainName'], $body);
        // Semantic severity tone, not layout: the card must carry the warning
        // tone the health classifier assigns, and must not carry the success one.
        self::assertStringContainsString('border-l-warning', $body);
        self::assertStringNotContainsString('border-l-success', $body);
        self::assertStringContainsString('Fully healthy', $body);
        self::assertMatchesRegularExpression(
            '/Fully healthy.*?>0</s',
            $body,
            'The "Fully healthy" counter must not include a domain with a currently-failing record.',
        );
    }

    #[Test]
    public function theDashboardTriageListSurfacesABrokenDomainInsteadOfGoingSilent(): void
    {
        $seeded = $this->seedDomainWithBrokenSpf();

        $seeded['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $seeded['client']->getResponse()->getContent();
        self::assertStringContainsString('Needs your attention', $body, 'The primary triage surface must not go silent on a live regression.');
        self::assertStringContainsString($seeded['domainName'], $body);
        self::assertStringContainsString('SPF record present but failing checks', $body, 'The row names the failing record, in the same words the domain page uses.');
    }

    /**
     * @return array{client: KernelBrowser, domainId: string, domainName: string, teamId: string}
     */
    private function seedDomainWithBrokenSpf(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);
        $persona = $fixtures->persona()
            ->emailPrefix('brokenspf-'.$suffix)
            ->teamName('Broken SPF '.$suffix)
            ->withDomain('brokenspf-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);
        $domain = $persona->domain;

        // Everything the OLD verdict looked at says "perfect": all three
        // verification timestamps present, an A-grade snapshot, a healthy MX
        // score, 99% of mail passing.
        $domain->spfVerifiedAt = new \DateTimeImmutable('-30 days');
        $domain->dkimVerifiedAt = new \DateTimeImmutable('-30 days');
        $domain->dmarcVerifiedAt = new \DateTimeImmutable('-30 days');

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

        $this->persistReport($em, $domain);

        // ...and the authoritative newest check says the SPF record is broken.
        $this->persistCheck($em, $domain, DnsCheckType::Spf, 'v=spf1 include:broken', isValid: false);
        $this->persistCheck($em, $domain, DnsCheckType::Dkim, 'v=DKIM1; k=rsa; p=MIIB', isValid: true);
        $this->persistCheck($em, $domain, DnsCheckType::Dmarc, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test', isValid: true);
        $this->persistCheck($em, $domain, DnsCheckType::Mx, 'mx.example', isValid: true);

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $domain->id->toString(),
            'domainName' => $domain->domain,
            'teamId' => $persona->team->id->toString(),
        ];
    }

    private function persistCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
    ): void {
        // Two rows per protocol, the older one passing: the query must pick the
        // NEWEST verdict, not merely "any failing check ever recorded".
        foreach ([['-10 days', true], ['-1 hour', $isValid]] as [$age, $valid]) {
            $check = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: $type,
                checkedAt: new \DateTimeImmutable($age),
                rawRecord: $rawRecord,
                isValid: $valid,
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

    private function persistReport(EntityManagerInterface $em, MonitoredDomain $domain): void
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
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 99,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $domain->domain,
        ));
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '5.6.7.8',
            count: 1,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));
    }
}

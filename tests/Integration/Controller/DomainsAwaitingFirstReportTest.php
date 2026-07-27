<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

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
 * A domain that has not been reported on yet must be presented as waiting, not
 * as failing. Before this, /app/domains showed a red "0.0%" beside an empty
 * sparkline for every brand-new domain — the card contradicted itself and
 * accused a correctly-configured domain of losing every message.
 */
final class DomainsAwaitingFirstReportTest extends WebTestCase
{
    #[Test]
    public function aDomainWithNoReportsSaysItIsWaitingForItsFirstReport(): void
    {
        $client = $this->clientWithVerifiedDomainWithoutReports();

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Waiting for first report', $body);
    }

    #[Test]
    public function aDomainWithNoReportsNeverShowsAZeroPercentPassRate(): void
    {
        $client = $this->clientWithVerifiedDomainWithoutReports();

        $client->request('GET', '/app/domains');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(
            '0.0%',
            $body,
            'A domain with no reports must never be shown a 0% pass rate.',
        );
    }

    #[Test]
    public function aDomainWithNoReportsDoesNotAdvertiseAZeroReportCount(): void
    {
        // "0 reports" next to "Waiting for first report" said the same thing
        // twice, the second time in a way that looked like a defect.
        $client = $this->clientWithVerifiedDomainWithoutReports();

        $client->request('GET', '/app/domains');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('0 reports', $body);
    }

    #[Test]
    public function aCorrectlyConfiguredDomainAwaitingItsFirstReportIsNotFlaggedForAttention(): void
    {
        $client = $this->clientWithVerifiedDomainWithoutReports();

        $client->request('GET', '/app/domains?status=attention');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            'No domains match',
            $body,
            'Waiting for a first report is not a problem the user has to fix.',
        );
    }

    #[Test]
    public function aDomainWhereEveryMessageFailedStillShowsItsRealZeroPassRate(): void
    {
        // Guards the other direction: a measured 0% is a genuine emergency and
        // must not be softened into "waiting for first report".
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $suffix = substr(uniqid('', true), -6);

        $persona = $fixtures->persona()
            ->emailPrefix('all-fail-'.$suffix)
            ->teamName('All Fail '.$suffix)
            ->withDomain('all-fail-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-2 days');
        $this->persistFailingReport($em, $persona->domain);
        $em->flush();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('0.0%', $body);
        self::assertStringNotContainsString('Waiting for first report', $body);
    }

    private function clientWithVerifiedDomainWithoutReports(): KernelBrowser
    {
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $suffix = substr(uniqid('', true), -6);

        $persona = $fixtures->persona()
            ->emailPrefix('awaiting-'.$suffix)
            ->teamName('Awaiting '.$suffix)
            ->withDomain('awaiting-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        // Fully configured: every protocol verified and a healthy DNS snapshot.
        // The only thing missing is a DMARC report.
        $persona->domain->spfVerifiedAt = new \DateTimeImmutable('-2 days');
        $persona->domain->dkimVerifiedAt = new \DateTimeImmutable('-2 days');
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-2 days');
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable('-1 hour'),
        ));
        $em->flush();
        $client->loginUser($persona->user);

        return $client;
    }

    private function persistFailingReport(EntityManagerInterface $em, MonitoredDomain $domain): void
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
            sourceIp: '5.6.7.8',
            count: 40,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));
    }
}

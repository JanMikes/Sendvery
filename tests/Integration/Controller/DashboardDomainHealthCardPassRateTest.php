<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
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
 * The Domain Health card on `/app` under the no-data pass-rate contract.
 *
 * A domain with no DMARC records has a NULL pass rate, which means "we have
 * nothing to grade", not "every message failed". Rendering the two the same way
 * told users their brand-new, correctly-configured domain was failing 100% of
 * its mail — in red, next to a red trend line, next to "0 reports".
 */
final class DashboardDomainHealthCardPassRateTest extends WebTestCase
{
    #[Test]
    public function aDomainWithNoReportsSaysItIsWaitingRatherThanShowingZeroPercent(): void
    {
        $client = $this->clientWithDomains(withReports: false);

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $card = $this->domainHealthCardBody($client);

        self::assertStringContainsString('Waiting for first report', $card);
        self::assertStringNotContainsString('0.0%', $card, 'Zero pass rate is a measurement; no reports is an absence of one.');
    }

    #[Test]
    public function aDomainWithNoReportsDoesNotAdvertiseItsZeroReportCount(): void
    {
        // "0 reports" beside "Waiting for first report" is the same fact twice,
        // and the second telling is the one that reads like a failure.
        $client = $this->clientWithDomains(withReports: false);

        $client->request('GET', '/app');

        $card = $this->domainHealthCardBody($client);
        self::assertStringNotContainsString('0 reports', $card);
    }

    #[Test]
    public function theSparklineIsTintedNeutralRatherThanRedWhenThereIsNothingToGrade(): void
    {
        $client = $this->clientWithDomains(withReports: false);

        $client->request('GET', '/app');

        $card = $this->domainHealthCardBody($client);
        // The trend line and the figure beside it are driven by the same shared
        // macro, so they can never disagree about whether this is a failure.
        self::assertStringContainsString('text-base-content/40', $card);
        self::assertStringNotContainsString('text-error', $card);
    }

    #[Test]
    public function aDomainWithRealReportsStillShowsItsMeasuredPassRate(): void
    {
        // The guard against over-correcting: suppressing the number must only
        // happen when there genuinely is no number.
        $client = $this->clientWithDomains(withReports: true);

        $client->request('GET', '/app');

        $card = $this->domainHealthCardBody($client);
        self::assertStringContainsString('40.0%', $card);
        self::assertStringNotContainsString('Waiting for first report', $card);
    }

    private function clientWithDomains(bool $withReports): KernelBrowser
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->persona()
            ->emailPrefix('health-card-'.substr(uniqid('', true), -6))
            ->withDomain('health-card-'.substr(uniqid('', true), -6).'.example')
            ->build();
        assert(null !== $persona->domain);

        if ($withReports) {
            $persona->domain->firstReportAt = new \DateTimeImmutable('-2 days');
            $this->persistReport($em, $persona->domain, pass: 4, fail: 6);
        }

        $em->flush();
        $client->loginUser($persona->user);

        return $client;
    }

    /**
     * The focus card and the attention list above the grid also mention domain
     * names and pass rates, so assertions have to be scoped to the card itself.
     */
    private function domainHealthCardBody(KernelBrowser $client): string
    {
        $card = $client->getCrawler()
            ->filter('h3:contains("Domain Health")')
            ->ancestors()
            ->filter('div.card')
            ->first();
        self::assertCount(1, $card, 'Domain Health card must render on /app');

        return $card->html();
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

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
 * A DMARC aggregate report can legitimately contain no records: RFC 7489 does
 * not require the reporting period to have seen traffic, and
 * `ProcessDmarcReportHandler` persists the report before it iterates records
 * with no non-empty guard, so such a row is storable today.
 *
 * Grading it 0.0% renders a red "every message failed" row for a report that
 * observed nothing at all — the same "unknown is not failure" confusion the
 * domain cards had, one level down.
 */
final class EmptyReportPassRateTest extends WebTestCase
{
    #[Test]
    public function theGlobalReportsListShowsNoFigureForAReportThatObservedNothing(): void
    {
        $data = $this->clientWithAnEmptyReport();

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $row = $this->rowFor($data['client'], $data['emptyReporter']);

        self::assertStringNotContainsString('0.0%', $row, 'Zero pass rate is a measurement; an empty report is the absence of one.');
        self::assertStringContainsString('—', $row);
        // Semantic severity tone, not styling: an unmeasured report must not be
        // painted with the failure tone the classifier reserves for real failures.
        self::assertStringNotContainsString('text-error', $row);
    }

    #[Test]
    public function theSameReportStillShowsItsFigureWhenItDidObserveMail(): void
    {
        // The guard against over-correcting: suppressing the number must only
        // happen when there genuinely is no number.
        $data = $this->clientWithAnEmptyReport();

        $data['client']->request('GET', '/app/reports');

        $row = $this->rowFor($data['client'], $data['measuredReporter']);
        self::assertStringContainsString('30.0%', $row);
    }

    #[Test]
    public function theDomainsOwnReportsTableAgreesWithTheGlobalOne(): void
    {
        $data = $this->clientWithAnEmptyReport();

        $data['client']->request('GET', sprintf('/app/domains/%s/reports', $data['domainId']));

        self::assertResponseIsSuccessful();
        $row = $this->rowFor($data['client'], $data['emptyReporter']);

        self::assertStringNotContainsString('0.0%', $row);
        self::assertStringContainsString('—', $row);
    }

    private function rowFor(KernelBrowser $client, string $reporterOrg): string
    {
        $rows = $client->getCrawler()->filter(sprintf('tr:contains("%s")', $reporterOrg));
        self::assertGreaterThan(0, $rows->count(), sprintf('Expected a report row for %s', $reporterOrg));

        return $rows->first()->html();
    }

    /**
     * @return array{client: KernelBrowser, domainId: string, emptyReporter: string, measuredReporter: string}
     */
    private function clientWithAnEmptyReport(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);
        $persona = $fixtures->persona()
            ->emailPrefix('empty-report-'.$suffix)
            ->withDomain('empty-report-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        $emptyReporter = 'quiet-period-'.$suffix.'.example';
        $measuredReporter = 'busy-period-'.$suffix.'.example';

        $em->persist($this->report($persona->domain, $emptyReporter));

        $measured = $this->report($persona->domain, $measuredReporter);
        $em->persist($measured);
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $measured,
            sourceIp: '1.2.3.4',
            count: 3,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
        ));
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $measured,
            sourceIp: '5.6.7.8',
            count: 7,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $persona->domain->domain,
        ));

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id->toString(),
            'emptyReporter' => $emptyReporter,
            'measuredReporter' => $measuredReporter,
        ];
    }

    private function report(MonitoredDomain $domain, string $reporterOrg): DmarcReport
    {
        return new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: $reporterOrg,
            reporterEmail: 'noreply@'.$reporterOrg,
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
    }
}

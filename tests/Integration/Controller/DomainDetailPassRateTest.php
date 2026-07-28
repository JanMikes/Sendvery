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
 * The "Pass Rate" stat card on `/app/domains/{id}`.
 *
 * The `/app` Domain Health card and the `/app/domains` cards were both taught
 * that a domain with no DMARC records has NO pass rate rather than a 0% one.
 * The domain's own detail page — the surface a user lands on straight after
 * adding a domain, and the one that spends the longest with no data — kept its
 * `COALESCE(..., 0)`, so it greeted every new domain with a red 0.0%.
 */
final class DomainDetailPassRateTest extends WebTestCase
{
    #[Test]
    public function aBrandNewDomainIsToldWhatItIsWaitingForInsteadOfBeingGradedZero(): void
    {
        $data = $this->clientWithDomain(withReports: false);

        $data['client']->request('GET', '/app/domains/'.$data['domainId']);

        self::assertResponseIsSuccessful();
        $card = $this->passRateCard($data['client']);

        self::assertStringNotContainsString('0.0%', $card, 'Zero pass rate is a measurement; no reports is the absence of one.');
        self::assertStringContainsString('Waiting for first report', $card);
        self::assertStringNotContainsString('text-error', $card);
    }

    #[Test]
    public function aDomainWithReportsStillShowsItsMeasuredPassRate(): void
    {
        // The guard against over-correcting: the number must still appear when
        // there genuinely is one.
        $data = $this->clientWithDomain(withReports: true);

        $data['client']->request('GET', '/app/domains/'.$data['domainId']);

        $card = $this->passRateCard($data['client']);
        self::assertStringContainsString('40.0%', $card);
        self::assertStringNotContainsString('Waiting for first report', $card);
    }

    private function passRateCard(KernelBrowser $client): string
    {
        $card = $client->getCrawler()
            ->filter('h3:contains("Pass Rate")')
            ->ancestors()
            ->filter('div.card')
            ->first();
        self::assertCount(1, $card, 'The Pass Rate stat card must render on the domain detail page');

        return $card->html();
    }

    /**
     * @return array{client: KernelBrowser, domainId: string}
     */
    private function clientWithDomain(bool $withReports): array
    {
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);
        $persona = $fixtures->persona()
            ->emailPrefix('detail-rate-'.$suffix)
            ->withDomain('detail-rate-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        if ($withReports) {
            $persona->domain->firstReportAt = new \DateTimeImmutable('-2 days');
            $this->persistReport($em, $persona->domain, pass: 4, fail: 6);
        }

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id->toString(),
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

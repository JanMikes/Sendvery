<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-082 — every dashboard page renders exactly one canonical H1 via the
 * `page_heading` block in `dashboard/layout.html.twig`. Without this guard a
 * future PR can either drop the H1 entirely (regressing the page-orientation
 * gap) or stamp a second one (the duplication risk that motivated this lift).
 */
final class DashboardPageHeadingsTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domainId: UuidInterface, domainName: string, reportId: UuidInterface}
     */
    private function createAuthenticatedClientWithData(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('heading')
            ->teamName('Heading Test')
            ->plan('personal')
            ->withDomain('heading-test.com')
            ->build();
        assert(null !== $persona->domain);
        $persona->domain->dmarcPolicy = DmarcPolicy::Reject;
        $em->flush();

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-heading-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
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
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
        ));
        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id,
            'domainName' => $persona->domain->domain,
            'reportId' => $reportId,
        ];
    }

    #[Test]
    public function overviewRendersDashboardHeading(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dashboard');
    }

    #[Test]
    public function domainsListRendersDomainsHeading(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Domains');
    }

    #[Test]
    public function reportsListRendersDmarcReportsHeading(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DMARC Reports');
    }

    #[Test]
    public function domainReportsRendersHeadingWithDomainName(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reports — '.$data['domainName']);
    }

    #[Test]
    public function billingRendersBillingHeading(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Billing');
    }

    #[Test]
    public function noDashboardPageRendersDoubleH1(): void
    {
        // Sweep guard: parses each rendered page with DOMXPath and asserts
        // exactly ONE H1. Catches both the "I forgot the override" regression
        // (zero H1s) and the "I left the inline H1 behind during migration"
        // regression (two H1s).
        $data = $this->createAuthenticatedClientWithData();
        $client = $data['client'];

        $pages = [
            '/app',
            '/app/domains',
            '/app/domains/'.$data['domainId'],
            '/app/domains/'.$data['domainId'].'/reports',
            '/app/domains/'.$data['domainId'].'/health',
            '/app/domains/'.$data['domainId'].'/senders',
            '/app/domains/'.$data['domainId'].'/blacklist',
            '/app/domains/'.$data['domainId'].'/dns-history',
            '/app/reports',
            '/app/reports/'.$data['reportId'],
            '/app/alerts',
            '/app/quarantine',
            '/app/mailboxes',
            '/app/settings/billing',
        ];

        foreach ($pages as $page) {
            $client->request('GET', $page);

            if ($client->getResponse()->isRedirection()) {
                continue;
            }

            self::assertResponseIsSuccessful(sprintf('Page %s did not return 200.', $page));

            $body = (string) $client->getResponse()->getContent();
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">'.$body);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $xpath = new \DOMXPath($document);
            $h1s = $xpath->query('//main//h1');
            assert($h1s instanceof \DOMNodeList);

            self::assertCount(
                1,
                $h1s,
                sprintf('Page %s rendered %d H1 elements inside <main>; expected exactly 1.', $page, $h1s->count()),
            );
        }
    }
}

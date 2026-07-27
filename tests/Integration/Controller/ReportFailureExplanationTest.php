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
 * A failing DMARC record has to explain itself on the report detail page: which
 * identifiers were used, whether they aligned with the From domain, and what the
 * user should do about it — on every plan, with no AI involved.
 */
final class ReportFailureExplanationTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domain: MonitoredDomain, report: DmarcReport, em: EntityManagerInterface}
     */
    private function bootClientWithReport(string $prefix, DmarcAlignment $alignment = DmarcAlignment::Relaxed): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($prefix)
            ->teamName('Failure Explanation')
            ->withDomain('explain.example')
            ->build();
        assert(null !== $persona->domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: $alignment,
            policyAspf: $alignment,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $client->loginUser($persona->user);

        return ['client' => $client, 'domain' => $persona->domain, 'report' => $report, 'em' => $em];
    }

    private function persistRecord(
        EntityManagerInterface $em,
        DmarcReport $report,
        string $sourceIp,
        AuthResult $dkim,
        AuthResult $spf,
        ?string $dkimDomain = null,
        ?string $dkimSelector = null,
        ?string $spfDomain = null,
        int $count = 12,
    ): void {
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: AuthResult::Pass === $dkim || AuthResult::Pass === $spf ? Disposition::None : Disposition::Reject,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $report->monitoredDomain->domain,
            dkimDomain: $dkimDomain,
            dkimSelector: $dkimSelector,
            spfDomain: $spfDomain,
            resolvedOrg: 'mailer.example',
        ));
        $em->flush();
    }

    private function requestDetail(string $prefix, \Closure $withRecords, DmarcAlignment $alignment = DmarcAlignment::Relaxed): string
    {
        $ctx = $this->bootClientWithReport($prefix, $alignment);
        $withRecords($ctx['em'], $ctx['report']);

        $ctx['client']->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();

        return (string) $ctx['client']->getResponse()->getContent();
    }

    #[Test]
    public function aFailingRecordShowsTheDkimSigningDomainSelectorAndSpfEnvelopeDomain(): void
    {
        // Without these three identifiers a user cannot tell whose signature was
        // on the mail, which key to look for in DNS, or what the return-path was.
        $body = $this->requestDetail('explain-ids', withRecords: function (EntityManagerInterface $em, DmarcReport $report): void {
            $this->persistRecord(
                $em,
                $report,
                '203.0.113.7',
                AuthResult::Fail,
                AuthResult::Fail,
                dkimDomain: 'sendgrid.net',
                dkimSelector: 's1',
                spfDomain: 'bounces.mailer.example',
            );
        });

        self::assertStringContainsString('sendgrid.net', $body);
        self::assertStringContainsString('s1', $body);
        self::assertStringContainsString('bounces.mailer.example', $body);
    }

    #[Test]
    public function aFailingRecordIsLabelledWithAnAlignmentVerdictNotJustRawAuthResults(): void
    {
        $body = $this->requestDetail('explain-verdict', withRecords: function (EntityManagerInterface $em, DmarcReport $report): void {
            $this->persistRecord(
                $em,
                $report,
                '203.0.113.8',
                AuthResult::Fail,
                AuthResult::Fail,
                dkimDomain: 'sendgrid.net',
                spfDomain: 'bounces.mailer.example',
            );
        });

        self::assertStringContainsString('DMARC fail', $body);
        self::assertStringContainsString('DKIM Not aligned', $body);
        self::assertStringContainsString('SPF Not aligned', $body);
    }

    #[Test]
    public function aFailingRecordExplainsWhyInPlainLanguageWithoutAnAiPlan(): void
    {
        // The persona is on the free plan, so the AI card must not render — and
        // the explanation must still be there.
        $body = $this->requestDetail('explain-noai', withRecords: function (EntityManagerInterface $em, DmarcReport $report): void {
            $this->persistRecord(
                $em,
                $report,
                '203.0.113.9',
                AuthResult::Fail,
                AuthResult::Fail,
                dkimDomain: 'sendgrid.net',
                spfDomain: 'bounces.mailer.example',
            );
        });

        self::assertStringNotContainsString('AI explanation', $body);
        self::assertStringContainsString('What failed, and what it means', $body);
        self::assertStringContainsString('DKIM did not align', $body);
        self::assertStringContainsString('SPF did not align', $body);
    }

    #[Test]
    public function unsignedMailIsExplainedAsMissingDkimRatherThanMisalignment(): void
    {
        $body = $this->requestDetail('explain-unsigned', withRecords: function (EntityManagerInterface $em, DmarcReport $report): void {
            $this->persistRecord($em, $report, '203.0.113.10', AuthResult::Fail, AuthResult::Fail);
        });

        self::assertStringContainsString('No DKIM signature was reported', $body);
        self::assertStringContainsString('No SPF result was reported', $body);
    }

    #[Test]
    public function strictAlignmentIsExplainedAsRequiringAnExactDomainMatch(): void
    {
        $body = $this->requestDetail(
            'explain-strict',
            function (EntityManagerInterface $em, DmarcReport $report): void {
                $this->persistRecord(
                    $em,
                    $report,
                    '203.0.113.11',
                    AuthResult::Fail,
                    AuthResult::Fail,
                    dkimDomain: 'mail.'.$report->monitoredDomain->domain,
                    spfDomain: 'mail.'.$report->monitoredDomain->domain,
                );
            },
            DmarcAlignment::Strict,
        );

        self::assertStringContainsString('strict alignment', $body);
    }

    #[Test]
    public function aReportWithNoFailuresSaysSoInsteadOfShowingAnEmptyPanel(): void
    {
        $body = $this->requestDetail('explain-clean', withRecords: function (EntityManagerInterface $em, DmarcReport $report): void {
            $this->persistRecord(
                $em,
                $report,
                '203.0.113.12',
                AuthResult::Pass,
                AuthResult::Pass,
                dkimDomain: $report->monitoredDomain->domain,
                spfDomain: $report->monitoredDomain->domain,
            );
        });

        self::assertStringContainsString('Nothing failed DMARC in this report', $body);
        self::assertStringNotContainsString('What failed, and what it means', $body);
    }

    #[Test]
    public function aReportWithNoMessageRowsDoesNotClaimACleanBillOfHealth(): void
    {
        $ctx = $this->bootClientWithReport('explain-empty');
        $ctx['em']->flush();

        $ctx['client']->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringContainsString('This report contains no message data', $body);
        self::assertStringNotContainsString('Nothing failed DMARC in this report', $body);
    }

    #[Test]
    public function aSenderWithFailuresStartsExpandedSoUsersDoNotHaveToHuntForIt(): void
    {
        $ctx = $this->bootClientWithReport('explain-open');
        $this->persistRecord(
            $ctx['em'],
            $ctx['report'],
            '203.0.113.13',
            AuthResult::Fail,
            AuthResult::Fail,
            dkimDomain: 'sendgrid.net',
            spfDomain: 'bounces.mailer.example',
        );

        $crawler = $ctx['client']->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('details[open]')->count(),
            'A sender group containing a DMARC failure must render expanded.',
        );
    }

    #[Test]
    public function aSenderWithNoFailuresStaysCollapsed(): void
    {
        $ctx = $this->bootClientWithReport('explain-closed');
        $this->persistRecord(
            $ctx['em'],
            $ctx['report'],
            '203.0.113.14',
            AuthResult::Pass,
            AuthResult::Pass,
            dkimDomain: $ctx['domain']->domain,
            spfDomain: $ctx['domain']->domain,
        );

        $crawler = $ctx['client']->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('details[open]'),
            'Nothing needs the user\'s attention, so nothing should be pre-expanded.',
        );
    }

    #[Test]
    public function theHeadlineCountsExplainWhichRuleTheyFollow(): void
    {
        // The list page and this page count a pass the same way; saying so stops
        // the per-record identifier detail from looking like a contradiction.
        $body = $this->requestDetail('explain-note', withRecords: function (EntityManagerInterface $em, DmarcReport $report): void {
            $this->persistRecord($em, $report, '203.0.113.15', AuthResult::Pass, AuthResult::Fail);
        });

        self::assertStringContainsString('already include DMARC alignment', $body);
        self::assertStringContainsString('Pass Rate column on the reports list', $body);
    }

    #[Test]
    public function onlyTheTenLargestFailingSourcesAreExpandedInline(): void
    {
        // A report can carry hundreds of failing IPs; the inline explainer stays
        // scannable and points at the raw table for the tail.
        $ctx = $this->bootClientWithReport('explain-cap');
        foreach (range(1, 12) as $index) {
            $this->persistRecord(
                $ctx['em'],
                $ctx['report'],
                '198.51.100.'.$index,
                AuthResult::Fail,
                AuthResult::Fail,
                dkimDomain: 'sendgrid.net',
                spfDomain: 'bounces.mailer.example',
                count: 100 - $index,
            );
        }

        $ctx['client']->request('GET', '/app/reports/'.$ctx['report']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringContainsString('Showing the 10 largest failing sources of 12', $body);
    }
}

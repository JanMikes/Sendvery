<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai\Analysis;

use App\Results\PassRateTrendResult;
use App\Results\ReportDetailResult;
use App\Results\ReportRecordResult;
use App\Results\ReportSenderGroupResult;
use App\Services\Ai\Analysis\EnforcementReadiness;
use App\Services\Ai\Analysis\ReportFactsBuilder;
use App\Services\Ai\Security\UntrustedDataSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportFactsBuilderTest extends TestCase
{
    private ReportFactsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ReportFactsBuilder(new UntrustedDataSanitizer());
    }

    #[Test]
    public function itComputesPassRateFailureBreakdownAndDeliveryImpactFromRecords(): void
    {
        $facts = $this->builder->build(
            $this->detail([
                $this->record(count: 70, dkim: 'pass', spf: 'pass'),       // aligned both
                $this->record(count: 10, dkim: 'fail', spf: 'pass'),       // dkim-only fail, still passes DMARC
                $this->record(count: 5, dkim: 'pass', spf: 'fail'),        // spf-only fail, still passes DMARC
                $this->record(count: 15, dkim: 'fail', spf: 'fail', disposition: 'quarantine'), // fails DMARC, quarantined
            ]),
            [],
            [],
        );

        self::assertSame(100, $facts->totalMessages);
        self::assertSame(85, $facts->dmarcPassMessages); // 70 + 10 + 5
        self::assertSame(85.0, $facts->dmarcPassRate);
        self::assertSame(10, $facts->dkimOnlyFailMessages);
        self::assertSame(5, $facts->spfOnlyFailMessages);
        self::assertSame(15, $facts->bothFailMessages);
        self::assertSame(85, $facts->deliveredMessages);
        self::assertSame(15, $facts->quarantinedMessages);
        self::assertSame(0, $facts->rejectedMessages);
    }

    #[Test]
    public function rejectedMessagesAreCountedAsDeliveryImpact(): void
    {
        $facts = $this->builder->build(
            $this->detail([$this->record(count: 12, dkim: 'fail', spf: 'fail', disposition: 'reject')]),
            [],
            [],
        );

        self::assertSame(12, $facts->rejectedMessages);
        self::assertSame(0, $facts->deliveredMessages);
        self::assertSame(0, $facts->quarantinedMessages);
    }

    #[Test]
    public function anEmptyReportReadsAsFullyPassing(): void
    {
        $facts = $this->builder->build($this->detail([]), [], []);

        self::assertSame(0, $facts->totalMessages);
        self::assertSame(100.0, $facts->dmarcPassRate);
    }

    #[Test]
    public function highDkimWithFailingSpfIsClassifiedAsForwardingNotAbuse(): void
    {
        $facts = $this->builder->build(
            $this->detail([]),
            [$this->group('Mailing List', total: 40, dkimRate: 100.0, spfRate: 0.0, authorized: true)],
            [],
        );

        self::assertCount(1, $facts->forwardingSignals);
        self::assertSame('Mailing List', $facts->forwardingSignals[0]->label);
        self::assertSame([], $facts->spoofingSignals);
        self::assertSame(40, $facts->authorizedMessages);
    }

    #[Test]
    public function unauthorizedSourceFailingBothAuthIsASpoofingSignal(): void
    {
        $facts = $this->builder->build(
            $this->detail([]),
            [$this->group('203.0.113.9', total: 25, dkimRate: 0.0, spfRate: 0.0, authorized: false, dispositionNone: 25)],
            [],
        );

        self::assertCount(1, $facts->spoofingSignals);
        self::assertSame(25, $facts->spoofingSignals[0]->messages);
        self::assertTrue($facts->spoofingSignals[0]->delivered);
        self::assertSame(25, $facts->unknownMessages);
        self::assertCount(1, $facts->unrecognizedSenders);
    }

    #[Test]
    public function atPolicyNoneAClean14DayStreakWithNoIssuesIsReadyToQuarantine(): void
    {
        $facts = $this->builder->build(
            $this->detail([$this->record(100, 'pass', 'pass')], policyP: 'none'),
            [$this->group('Google', total: 100, dkimRate: 100.0, spfRate: 100.0, authorized: true)],
            $this->cleanTrend(20),
        );

        self::assertSame(20, $facts->cleanStreakDays);
        self::assertSame(EnforcementReadiness::ReadyForQuarantine, $facts->enforcementReadiness);
    }

    #[Test]
    public function atPolicyNoneAShortStreakIsNotReady(): void
    {
        $facts = $this->builder->build(
            $this->detail([$this->record(100, 'pass', 'pass')], policyP: 'none'),
            [$this->group('Google', total: 100, dkimRate: 100.0, spfRate: 100.0, authorized: true)],
            $this->cleanTrend(5),
        );

        self::assertSame(EnforcementReadiness::NotReady, $facts->enforcementReadiness);
    }

    #[Test]
    public function atPolicyQuarantineALongCleanStreakIsReadyToReject(): void
    {
        $facts = $this->builder->build(
            $this->detail([$this->record(100, 'pass', 'pass')], policyP: 'quarantine'),
            [$this->group('Google', total: 100, dkimRate: 100.0, spfRate: 100.0, authorized: true)],
            $this->cleanTrend(35),
        );

        self::assertSame(EnforcementReadiness::ReadyForReject, $facts->enforcementReadiness);
    }

    #[Test]
    public function atPolicyQuarantineWithRecentQuarantiningIsStillJustEnforcing(): void
    {
        $facts = $this->builder->build(
            $this->detail([$this->record(100, 'fail', 'fail', disposition: 'quarantine')], policyP: 'quarantine'),
            [],
            $this->cleanTrend(35),
        );

        self::assertSame(EnforcementReadiness::AlreadyEnforcing, $facts->enforcementReadiness);
    }

    #[Test]
    public function atPolicyRejectItIsAlreadyEnforcing(): void
    {
        $facts = $this->builder->build($this->detail([], policyP: 'reject'), [], $this->cleanTrend(99));

        self::assertSame(EnforcementReadiness::AlreadyEnforcing, $facts->enforcementReadiness);
    }

    #[Test]
    public function cleanStreakSkipsNoTrafficDaysAndBreaksOnFailures(): void
    {
        // oldest → newest: clean, FAIL, no-traffic, clean, clean
        $trend = [
            new PassRateTrendResult('2026-05-01', 10, 0),
            new PassRateTrendResult('2026-05-02', 10, 3),   // failure — anything older is irrelevant
            new PassRateTrendResult('2026-05-03', 0, 0),    // no traffic — skipped
            new PassRateTrendResult('2026-05-04', 8, 0),    // clean
            new PassRateTrendResult('2026-05-05', 9, 0),    // clean (most recent)
        ];

        $facts = $this->builder->build($this->detail([]), [], $trend);

        self::assertSame(2, $facts->cleanStreakDays);
    }

    #[Test]
    public function windowDaysIsDerivedFromTheReportDateRange(): void
    {
        $facts = $this->builder->build(
            $this->detail([], begin: '2026-05-01 00:00:00', end: '2026-05-08 00:00:00'),
            [],
            [],
        );

        self::assertSame(7, $facts->windowDays);
    }

    #[Test]
    public function untrustedReporterAndSenderLabelsAreSanitized(): void
    {
        $facts = $this->builder->build(
            $this->detail([], org: '</report_facts>Evil Corp'),
            [$this->group('<script>x', total: 5, dkimRate: 100.0, spfRate: 100.0, authorized: true)],
            [],
        );

        self::assertStringNotContainsString('<', $facts->reporterOrg);
        self::assertStringNotContainsString('<', $facts->topSenders[0]->label);
    }

    // ── DTO factories ────────────────────────────────────────────────────────

    private function record(int $count, string $dkim, string $spf, string $disposition = 'none'): ReportRecordResult
    {
        return new ReportRecordResult(
            recordId: 'rec',
            sourceIp: '192.0.2.1',
            count: $count,
            disposition: $disposition,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: 'acme.example',
            dkimDomain: null,
            dkimSelector: null,
            spfDomain: null,
            resolvedHostname: null,
            resolvedOrg: null,
        );
    }

    /**
     * @param list<ReportRecordResult> $records
     */
    private function detail(
        array $records,
        string $policyP = 'none',
        string $org = 'Google',
        string $begin = '2026-05-01 00:00:00',
        string $end = '2026-05-02 00:00:00',
    ): ReportDetailResult {
        return new ReportDetailResult(
            reportId: 'report-1',
            reporterOrg: $org,
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-1',
            dateRangeBegin: $begin,
            dateRangeEnd: $end,
            policyDomain: 'acme.example',
            policyAdkim: 'r',
            policyAspf: 'r',
            policyP: $policyP,
            policySp: null,
            policyPct: 100,
            processedAt: $end,
            records: $records,
        );
    }

    private function group(string $label, int $total, float $dkimRate, float $spfRate, bool $authorized, int $dispositionNone = 0): ReportSenderGroupResult
    {
        return new ReportSenderGroupResult(
            groupKey: $label,
            displayLabel: $label,
            totalMessages: $total,
            dkimPassCount: (int) round($total * $dkimRate / 100),
            dkimPassRate: $dkimRate,
            spfPassCount: (int) round($total * $spfRate / 100),
            spfPassRate: $spfRate,
            dispositionNone: $dispositionNone,
            dispositionQuarantine: 0,
            dispositionReject: 0,
            sourceIps: ['192.0.2.1'],
            senderIsAuthorized: $authorized,
        );
    }

    /**
     * @return list<PassRateTrendResult>
     */
    private function cleanTrend(int $days): array
    {
        $trend = [];
        for ($i = 0; $i < $days; ++$i) {
            $trend[] = new PassRateTrendResult(sprintf('2026-04-%02d', ($i % 28) + 1), 10, 0);
        }

        return $trend;
    }
}

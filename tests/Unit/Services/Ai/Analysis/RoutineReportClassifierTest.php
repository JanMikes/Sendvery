<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai\Analysis;

use App\Services\Ai\Analysis\EnforcementReadiness;
use App\Services\Ai\Analysis\ReportInsightFacts;
use App\Services\Ai\Analysis\RoutineReportClassifier;
use App\Services\Ai\Analysis\SpoofingSignal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoutineReportClassifierTest extends TestCase
{
    private RoutineReportClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RoutineReportClassifier();
    }

    #[Test]
    public function aCleanAllPassReportFromKnownSendersIsRoutine(): void
    {
        self::assertTrue($this->classifier->isRoutine($this->facts()));
    }

    #[Test]
    public function eachProblemSignalMakesItNonRoutine(): void
    {
        self::assertFalse($this->classifier->isRoutine($this->facts(passRate: 97.9)), 'low pass rate');
        self::assertFalse($this->classifier->isRoutine($this->facts(spoofing: [new SpoofingSignal('x', 5, true)])), 'spoofing');
        self::assertFalse($this->classifier->isRoutine($this->facts(quarantined: 1)), 'quarantined mail');
        self::assertFalse($this->classifier->isRoutine($this->facts(rejected: 1)), 'rejected mail');
        self::assertFalse($this->classifier->isRoutine($this->facts(unknown: 1)), 'unknown sender mail');
    }

    #[Test]
    public function theTemplatedExplanationIsBuiltFromFactsWithNoApiCall(): void
    {
        $result = $this->classifier->buildTemplatedExplanation($this->facts());

        self::assertStringContainsString('Google', $result->explanation);
        self::assertStringContainsString('acme.example', $result->explanation);
        self::assertStringContainsString('1,000', $result->explanation); // thousands separator
        self::assertStringContainsString('No action is needed', $result->explanation);
    }

    /**
     * @param list<SpoofingSignal> $spoofing
     */
    private function facts(
        float $passRate = 100.0,
        int $quarantined = 0,
        int $rejected = 0,
        int $unknown = 0,
        array $spoofing = [],
    ): ReportInsightFacts {
        return new ReportInsightFacts(
            reporterOrg: 'Google',
            protectedDomain: 'acme.example',
            windowDays: 1,
            totalMessages: 1000,
            dmarcPassMessages: 1000,
            dmarcPassRate: $passRate,
            dkimOnlyFailMessages: 0,
            spfOnlyFailMessages: 0,
            bothFailMessages: 0,
            deliveredMessages: 1000,
            quarantinedMessages: $quarantined,
            rejectedMessages: $rejected,
            authorizedMessages: 1000,
            unknownMessages: $unknown,
            distinctSenders: 1,
            topSenders: [],
            forwardingSignals: [],
            spoofingSignals: $spoofing,
            unrecognizedSenders: [],
            policy: 'none',
            subdomainPolicy: null,
            policyPct: 100,
            cleanStreakDays: 30,
            enforcementReadiness: EnforcementReadiness::ReadyForQuarantine,
        );
    }
}

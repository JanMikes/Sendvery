<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\DmarcPolicyAdvisor;
use App\Value\DmarcPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcPolicyAdvisorTest extends TestCase
{
    #[Test]
    public function adviceForPolicyNoneNotReady(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::None, passRate: 72.5, reportsCount: 12);

        self::assertSame(DmarcPolicy::None, $result->currentPolicy);
        self::assertSame(DmarcPolicy::Quarantine, $result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('Still collecting data', $result->reasonText);
        self::assertStringContainsString('above 90%', $result->reasonText);
        self::assertSame(72.5, $result->passRate);
        self::assertSame(12, $result->reportsCount);
    }

    #[Test]
    public function adviceForPolicyNoneNotReadyDueToInsufficientReports(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::None, passRate: 99.0, reportsCount: 2);

        self::assertSame(DmarcPolicy::Quarantine, $result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('Need at least 3 parsed reports', $result->reasonText);
    }

    #[Test]
    public function adviceForPolicyNoneReady(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::None, passRate: 96.4, reportsCount: 8);

        self::assertSame(DmarcPolicy::Quarantine, $result->recommendedNextPolicy);
        self::assertTrue($result->eligibleForNextTier);
        self::assertStringContainsString('ready to begin gradual enforcement at p=quarantine', $result->reasonText);
        self::assertStringContainsString('96.4%', $result->reasonText);
    }

    #[Test]
    public function adviceForPolicyQuarantineNotReady(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::Quarantine, passRate: 91.0, reportsCount: 25);

        self::assertSame(DmarcPolicy::Reject, $result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('Still collecting data', $result->reasonText);
        self::assertStringContainsString('above 95%', $result->reasonText);
    }

    #[Test]
    public function adviceForPolicyQuarantineReady(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::Quarantine, passRate: 98.2, reportsCount: 25);

        self::assertSame(DmarcPolicy::Reject, $result->recommendedNextPolicy);
        self::assertTrue($result->eligibleForNextTier);
        self::assertStringContainsString('ready to lock down with p=reject', $result->reasonText);
        self::assertStringContainsString('98.2%', $result->reasonText);
    }

    #[Test]
    public function adviceForPolicyReject(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::Reject, passRate: 99.9, reportsCount: 50);

        self::assertSame(DmarcPolicy::Reject, $result->currentPolicy);
        self::assertNull($result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('strongest DMARC posture', $result->reasonText);
    }

    #[Test]
    public function adviceForRejectWhenNoReportsStillTerminal(): void
    {
        // Edge case: a domain published at p=reject from day one (e.g. an
        // expert migrating from a previous platform). The "no reports yet"
        // copy must NOT override the terminal-tier reassurance.
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::Reject, passRate: 0.0, reportsCount: 0);

        self::assertNull($result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('strongest DMARC posture', $result->reasonText);
    }

    #[Test]
    public function adviceForZeroReports(): void
    {
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::None, passRate: 0.0, reportsCount: 0);

        self::assertSame(DmarcPolicy::None, $result->currentPolicy);
        self::assertSame(DmarcPolicy::Quarantine, $result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('No reports parsed yet', $result->reasonText);
        self::assertStringContainsString('24 hours of publishing DMARC', $result->reasonText);
    }

    #[Test]
    public function adviceForZeroReportsAtQuarantine(): void
    {
        // Symmetry: zero reports at p=quarantine still surfaces the
        // wait-for-data copy and recommends p=reject as the next tier.
        $advisor = new DmarcPolicyAdvisor();

        $result = $advisor->adviseFor(DmarcPolicy::Quarantine, passRate: 0.0, reportsCount: 0);

        self::assertSame(DmarcPolicy::Reject, $result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertStringContainsString('No reports parsed yet', $result->reasonText);
    }
}

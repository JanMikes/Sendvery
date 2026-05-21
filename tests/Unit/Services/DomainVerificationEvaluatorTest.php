<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DomainVerificationStatusResult;
use App\Services\DomainVerificationEvaluator;
use App\Value\DomainVerificationSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class DomainVerificationEvaluatorTest extends TestCase
{
    #[Test]
    public function criticalWhenDmarcNeverSeen(): void
    {
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(dmarcVerifiedAt: null));

        self::assertSame(DomainVerificationSeverity::Critical, $severity);
    }

    #[Test]
    public function infoWhenDmarcVerifiedRecentlyAndOneCheckFails(): void
    {
        // Settling window: any failure within 24h of first verifying is treated
        // as DNS propagation, not a real outage. Surface as informational only.
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-21 09:00:00'),
            consecutiveDmarcFailures: 1,
        ));

        self::assertSame(DomainVerificationSeverity::Info, $severity);
    }

    #[Test]
    public function infoWhenSustainedFailureButStillInSettlingWindow(): void
    {
        // Within the settling window we don't escalate to Critical even at 2+ failures —
        // it's the right outcome for the legitimate "user is still wrangling their DNS
        // panel" case where retries are expected.
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-21 09:00:00'),
            consecutiveDmarcFailures: 5,
        ));

        self::assertSame(DomainVerificationSeverity::Info, $severity);
    }

    #[Test]
    public function infoWhenSingleFailureOutsideSettlingWindow(): void
    {
        // Outside settling, one missed check is almost always a transient resolver
        // hiccup — watch but don't alarm until it's sustained.
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
            consecutiveDmarcFailures: 1,
        ));

        self::assertSame(DomainVerificationSeverity::Info, $severity);
    }

    #[Test]
    public function criticalWhenSustainedFailureOutsideSettlingWindow(): void
    {
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
            consecutiveDmarcFailures: 2,
        ));

        self::assertSame(DomainVerificationSeverity::Critical, $severity);
    }

    #[Test]
    public function warningWhenDmarcValidButNoReportsAfter48Hours(): void
    {
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-19 09:00:00'),
            firstReportAt: null,
            consecutiveDmarcFailures: 0,
        ));

        self::assertSame(DomainVerificationSeverity::Warning, $severity);
    }

    #[Test]
    public function okWhenWithinFirstReportGracePeriod(): void
    {
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-21 09:00:00'),
            firstReportAt: null,
            consecutiveDmarcFailures: 0,
        ));

        self::assertSame(DomainVerificationSeverity::Ok, $severity);
    }

    #[Test]
    public function okWhenReportsAlreadyFlowing(): void
    {
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-19 09:00:00'),
            firstReportAt: new \DateTimeImmutable('2026-05-20 06:00:00'),
            consecutiveDmarcFailures: 0,
        ));

        self::assertSame(DomainVerificationSeverity::Ok, $severity);
    }

    private function buildStatus(
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?\DateTimeImmutable $firstReportAt = null,
        int $consecutiveDmarcFailures = 0,
    ): DomainVerificationStatusResult {
        return new DomainVerificationStatusResult(
            domainId: 'd1',
            domainName: 'example.com',
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: $dmarcVerifiedAt,
            firstReportAt: $firstReportAt,
            consecutiveDmarcFailures: $consecutiveDmarcFailures,
        );
    }

    private function clockAt(string $when): ClockInterface
    {
        $now = new \DateTimeImmutable($when);

        return new class ($now) implements ClockInterface {
            public function __construct(private \DateTimeImmutable $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}

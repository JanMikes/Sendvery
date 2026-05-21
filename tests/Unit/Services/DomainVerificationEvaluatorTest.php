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
    public function criticalWhenDmarcVerifiedButNowMissing(): void
    {
        $evaluator = new DomainVerificationEvaluator($this->clockAt('2026-05-21 12:00:00'));

        $severity = $evaluator->severity($this->buildStatus(
            dmarcVerifiedAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
            dmarcCurrentlyValid: false,
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
            dmarcCurrentlyValid: true,
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
            dmarcCurrentlyValid: true,
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
            dmarcCurrentlyValid: true,
        ));

        self::assertSame(DomainVerificationSeverity::Ok, $severity);
    }

    private function buildStatus(
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?\DateTimeImmutable $firstReportAt = null,
        bool $dmarcCurrentlyValid = false,
    ): DomainVerificationStatusResult {
        return new DomainVerificationStatusResult(
            domainId: 'd1',
            domainName: 'example.com',
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: $dmarcVerifiedAt,
            firstReportAt: $firstReportAt,
            dmarcCurrentlyValid: $dmarcCurrentlyValid,
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

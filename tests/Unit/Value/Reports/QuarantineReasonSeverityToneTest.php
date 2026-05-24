<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Reports;

use App\Value\Reports\QuarantineReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the TASK-071 `severityTone()` map. The enum owns the
 * mapping so templates don't drift; this test guards each case explicitly
 * AND walks every enum case so a future reason added without a tone trips
 * the match's exhaustive check rather than silently rendering as black.
 */
final class QuarantineReasonSeverityToneTest extends TestCase
{
    #[Test]
    public function planOverageMapsToErrorTone(): void
    {
        self::assertSame('error', QuarantineReason::PlanOverage->severityTone());
    }

    #[Test]
    public function unverifiedDomainMapsToWarningTone(): void
    {
        self::assertSame('warning', QuarantineReason::UnverifiedDomain->severityTone());
    }

    #[Test]
    public function unknownDomainMapsToInfoTone(): void
    {
        self::assertSame('info', QuarantineReason::UnknownDomain->severityTone());
    }

    #[Test]
    public function everyEnumCaseHasATone(): void
    {
        // Drift guard: if anybody adds a new QuarantineReason without
        // extending the match(), match() throws UnhandledMatchError here.
        foreach (QuarantineReason::cases() as $reason) {
            self::assertContains(
                $reason->severityTone(),
                ['error', 'warning', 'info'],
                'Reason '.$reason->value.' must map to one of error|warning|info.',
            );
        }
    }
}

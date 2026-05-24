<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Reports;

use App\Value\Reports\QuarantineReason;
use App\Value\Reports\QuarantineReasonFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuarantineReasonFilterTest extends TestCase
{
    #[Test]
    public function eachFilterCaseMapsToTheMatchingQuarantineReason(): void
    {
        self::assertSame(
            QuarantineReason::UnknownDomain,
            QuarantineReasonFilter::UnknownDomain->toQuarantineReason(),
        );
        self::assertSame(
            QuarantineReason::UnverifiedDomain,
            QuarantineReasonFilter::UnverifiedDomain->toQuarantineReason(),
        );
        self::assertSame(
            QuarantineReason::PlanOverage,
            QuarantineReasonFilter::PlanOverage->toQuarantineReason(),
        );
    }

    #[Test]
    public function filterValuesAreInLockstepWithReasonValues(): void
    {
        // Drift guard: if anybody renames a QuarantineReason case without
        // updating the filter enum, this test fails immediately rather than
        // surfacing as a phantom "no matches" empty state.
        foreach (QuarantineReasonFilter::cases() as $filter) {
            self::assertSame(
                $filter->value,
                $filter->toQuarantineReason()->value,
            );
        }
    }
}

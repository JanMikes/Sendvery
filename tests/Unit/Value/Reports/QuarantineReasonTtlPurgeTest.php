<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Reports;

use App\Value\Reports\QuarantineReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuarantineReasonTtlPurgeTest extends TestCase
{
    #[Test]
    public function aReportWithheldByThePlanCapHasNoExpiryLifecycleAtAll(): void
    {
        self::assertFalse(
            QuarantineReason::PlanOverage->isTtlPurgeable(),
            'The report belongs to the customer and is withheld for a billing reason; a retention TTL must never delete it.',
        );
    }

    #[Test]
    public function reportsForDomainsNobodyClaimedDoExpire(): void
    {
        self::assertTrue(
            QuarantineReason::UnknownDomain->isTtlPurgeable(),
            'Nobody can ever be handed this report, so holding it forever only grows the table.',
        );
        self::assertTrue(QuarantineReason::UnverifiedDomain->isTtlPurgeable());
    }

    #[Test]
    public function thePurgeableSetIsDerivedFromTheRulePerReason(): void
    {
        self::assertSame(
            [QuarantineReason::UnknownDomain, QuarantineReason::UnverifiedDomain],
            QuarantineReason::ttlPurgeable(),
            'The list feeding query filters must come from isTtlPurgeable(), so a reason added later cannot become deletable by omission.',
        );
    }
}

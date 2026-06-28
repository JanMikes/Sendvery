<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\ManagedDmarcPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AutoRampStageTest extends TestCase
{
    #[Test]
    public function climbsTheLadderMonitoringToComplete(): void
    {
        self::assertSame(AutoRampStage::Quarantine, AutoRampStage::Monitoring->next());
        self::assertSame(AutoRampStage::Reject, AutoRampStage::Quarantine->next());
        self::assertSame(AutoRampStage::Complete, AutoRampStage::Reject->next());
        self::assertNull(AutoRampStage::Complete->next());
    }

    #[Test]
    public function previousWalksBackForRollback(): void
    {
        self::assertSame(AutoRampStage::Reject, AutoRampStage::Complete->previous());
        self::assertSame(AutoRampStage::Quarantine, AutoRampStage::Reject->previous());
        self::assertSame(AutoRampStage::Monitoring, AutoRampStage::Quarantine->previous());
        self::assertNull(AutoRampStage::Monitoring->previous());
    }

    #[Test]
    public function fromPolicyMapsDmarcPolicyToStage(): void
    {
        self::assertSame(AutoRampStage::Monitoring, AutoRampStage::fromPolicy(null));
        self::assertSame(AutoRampStage::Monitoring, AutoRampStage::fromPolicy(DmarcPolicy::None));
        self::assertSame(AutoRampStage::Quarantine, AutoRampStage::fromPolicy(DmarcPolicy::Quarantine));
        self::assertSame(AutoRampStage::Reject, AutoRampStage::fromPolicy(DmarcPolicy::Reject));
    }

    #[Test]
    public function targetPolicyResolvesPerStage(): void
    {
        self::assertSame(DmarcPolicy::None, AutoRampStage::Monitoring->targetPolicy()->p);
        self::assertSame(DmarcPolicy::Quarantine, AutoRampStage::Quarantine->targetPolicy()->p);
        self::assertSame(DmarcPolicy::Reject, AutoRampStage::Reject->targetPolicy()->p);
        self::assertSame(DmarcPolicy::Reject, AutoRampStage::Complete->targetPolicy()->p);
    }

    #[Test]
    public function targetPolicyDefaultsToFullCoverageAndNoSubdomainOverride(): void
    {
        $target = AutoRampStage::Quarantine->targetPolicy();

        self::assertNull($target->sp);
        self::assertSame(100, $target->pct);
    }

    #[Test]
    public function targetPolicyCarriesTheCustomersSubdomainPolicyAndCoverageAcrossAStep(): void
    {
        // A ramp step must change only `p` — the customer's explicit sp=none
        // (subdomains intentionally exempt) and pct<100 must survive, or the
        // ramp would silently tighten enforcement against their stated intent.
        $current = new ManagedDmarcPolicy(DmarcPolicy::None, DmarcPolicy::None, 50);

        $target = AutoRampStage::Quarantine->targetPolicy($current);

        self::assertSame(DmarcPolicy::Quarantine, $target->p);
        self::assertSame(DmarcPolicy::None, $target->sp);
        self::assertSame(50, $target->pct);
    }
}

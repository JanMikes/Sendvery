<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\DmarcPolicy;
use App\Value\Dns\ManagedDmarcPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagedDmarcPolicyTest extends TestCase
{
    #[Test]
    public function monitoringIsPolicyNone(): void
    {
        $policy = ManagedDmarcPolicy::monitoring();

        self::assertSame(DmarcPolicy::None, $policy->p);
        self::assertNull($policy->sp);
        self::assertSame(100, $policy->pct);
    }

    #[Test]
    public function equalsComparesPolicySubdomainAndPct(): void
    {
        $base = new ManagedDmarcPolicy(DmarcPolicy::Quarantine, DmarcPolicy::Quarantine, 100);

        self::assertTrue($base->equals(new ManagedDmarcPolicy(DmarcPolicy::Quarantine, DmarcPolicy::Quarantine, 100)));
        self::assertFalse($base->equals(new ManagedDmarcPolicy(DmarcPolicy::Reject, DmarcPolicy::Quarantine, 100)));
        self::assertFalse($base->equals(new ManagedDmarcPolicy(DmarcPolicy::Quarantine, null, 100)));
        self::assertFalse($base->equals(new ManagedDmarcPolicy(DmarcPolicy::Quarantine, DmarcPolicy::Quarantine, 50)));
    }
}

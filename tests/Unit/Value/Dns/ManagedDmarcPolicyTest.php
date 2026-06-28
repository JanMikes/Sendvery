<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagedDmarcPolicyTest extends TestCase
{
    #[Test]
    public function monitoringIsPolicyNone(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function equalsComparesPolicySubdomainAndPct(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

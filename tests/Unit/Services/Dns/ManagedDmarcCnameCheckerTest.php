<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagedDmarcCnameCheckerTest extends TestCase
{
    #[Test]
    public function verifiedWhenCnamePointsAtSendvery(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function pointsElsewhereWhenCnameResolvesToAnotherTarget(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function missingWhenNoCnameExists(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function matchIsCaseInsensitiveAndIgnoresTrailingDot(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

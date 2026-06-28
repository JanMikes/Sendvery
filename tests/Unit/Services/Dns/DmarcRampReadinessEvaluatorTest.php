<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcRampReadinessEvaluatorTest extends TestCase
{
    #[Test]
    public function blocksAdvanceOnThinDataEvenAtHighPassRate(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function requiresVerifiedCnameBeforeRecommendingTightening(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function enforcesSevenDayDwell(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function noneToQuarantineNeeds95PercentOver30DaysAndTwoSources(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function quarantineToRejectNeeds99PercentOver60Days(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function flagsRegressionWhenAuthorizedSourceStartsFailing(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function rejectIsTerminal(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function derivesCurrentStageFromThePublishedPolicy(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Dashboard;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ManagedDmarcControllerTest extends IntegrationTestCase
{
    #[Test]
    public function freePlanSeesAnUpgradeNudgeInsteadOfTheManagedToggle(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function proPlanCanEnableManagedDmarc(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function aForgedPostForAnotherTeamsDomainIsRejected(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function csrfIsEnforcedOnEachWriteRoute(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function managedCardIsHiddenWhenCloudflareIsUnconfigured(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

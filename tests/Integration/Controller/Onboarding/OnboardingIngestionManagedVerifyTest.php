<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Onboarding;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OnboardingIngestionManagedVerifyTest extends IntegrationTestCase
{
    #[Test]
    public function rendersVerifiedWhenTheCnameResolves(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function keepsPollingWhenTheCnameIsMissing(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function explainsWhenTheDmarcPointsElsewhere(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function blocksEnableWhileCoexistingDmarcTxtIsLive(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

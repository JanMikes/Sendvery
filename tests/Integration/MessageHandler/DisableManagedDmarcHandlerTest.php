<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class DisableManagedDmarcHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function defersHostedTxtDeletionWhileCnameStillPointsAtUs(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function deletesOnceTheCnameIsGone(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PublishHostedDmarcRecordWhenManagedEnabledTest extends IntegrationTestCase
{
    #[Test]
    public function idStaysNullAndIsRetriedBySyncOnPublishFailure(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

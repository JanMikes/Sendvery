<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecordManagedDmarcPolicyChangeTest extends IntegrationTestCase
{
    #[Test]
    public function writesAnAuditRowWithActorAndSource(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

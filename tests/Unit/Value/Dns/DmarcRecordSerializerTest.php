<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcRecordSerializerTest extends TestCase
{
    #[Test]
    public function serializesAFullRejectPolicyInCanonicalTagOrder(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function omitsPctWhen100AndSpWhenNull(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function joinsMultipleRuaWithMailtoAndNoSpaces(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function emitsFoWhenSupplied(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function dmarcRuaInstructionOutputIsByteIdenticalAfterDelegating(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function preservesUnknownCustomerTagsThroughTheArrayPath(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

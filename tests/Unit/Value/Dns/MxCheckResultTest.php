<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\MxCheckResult;
use App\Value\Dns\MxRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MxCheckResultTest extends TestCase
{
    #[Test]
    public function is_passing_with_reachable_server(): void
    {
        $records = [new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)];
        $result = new MxCheckResult($records, []);

        self::assertTrue($result->isPassing());
        self::assertTrue($result->hasRecords());
    }

    #[Test]
    public function is_not_passing_with_no_records(): void
    {
        $result = new MxCheckResult([], []);

        self::assertFalse($result->isPassing());
        self::assertFalse($result->hasRecords());
    }

    #[Test]
    public function is_not_passing_when_all_unreachable(): void
    {
        $records = [new MxRecord('mail.example.com', 10, '1.2.3.4', false, null)];
        $result = new MxCheckResult($records, []);

        self::assertFalse($result->isPassing());
    }
}

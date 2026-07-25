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
    public function isPassingWithReachableServer(): void
    {
        $records = [new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)];
        $result = new MxCheckResult($records, []);

        self::assertTrue($result->isPassing());
        self::assertTrue($result->hasRecords());
    }

    #[Test]
    public function isNotPassingWithNoRecords(): void
    {
        $result = new MxCheckResult([], []);

        self::assertFalse($result->isPassing());
        self::assertFalse($result->hasRecords());
    }

    #[Test]
    public function isPassingWhenResolvableButUnreachableOnPort25(): void
    {
        // Outbound port 25 is blocked on many hosts (including ours) — a failed
        // probe says nothing about the domain, so DNS-valid MX must still pass.
        $records = [new MxRecord('mail.example.com', 10, '1.2.3.4', false, null)];
        $result = new MxCheckResult($records, []);

        self::assertTrue($result->isPassing());
    }

    #[Test]
    public function isNotPassingWhenNoRecordResolvesToAnIp(): void
    {
        $records = [new MxRecord('mail.example.com', 10, null, false, null)];
        $result = new MxCheckResult($records, []);

        self::assertFalse($result->isPassing());
    }
}

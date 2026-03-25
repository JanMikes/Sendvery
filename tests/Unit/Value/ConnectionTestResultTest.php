<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\ConnectionTestResult;
use PHPUnit\Framework\TestCase;

final class ConnectionTestResultTest extends TestCase
{
    public function testSuccessResult(): void
    {
        $result = new ConnectionTestResult(
            success: true,
            error: null,
            mailboxCount: 42,
        );

        self::assertTrue($result->success);
        self::assertNull($result->error);
        self::assertSame(42, $result->mailboxCount);
    }

    public function testFailureResult(): void
    {
        $result = new ConnectionTestResult(
            success: false,
            error: 'Connection refused',
            mailboxCount: 0,
        );

        self::assertFalse($result->success);
        self::assertSame('Connection refused', $result->error);
        self::assertSame(0, $result->mailboxCount);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\AiQuotaExceeded;
use PHPUnit\Framework\TestCase;

final class AiQuotaExceededTest extends TestCase
{
    public function testCarriesUsageAndLimit(): void
    {
        $exception = new AiQuotaExceeded(used: 50, limit: 50);

        self::assertSame(50, $exception->used);
        self::assertSame(50, $exception->limit);
        self::assertStringContainsString('50 of 50', $exception->getMessage());
    }
}

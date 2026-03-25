<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\PassRateTrendResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PassRateTrendResultTest extends TestCase
{
    #[Test]
    public function itCanBeConstructed(): void
    {
        $result = new PassRateTrendResult(
            date: '2025-06-15',
            passCount: 100,
            failCount: 5,
        );

        self::assertSame('2025-06-15', $result->date);
        self::assertSame(100, $result->passCount);
        self::assertSame(5, $result->failCount);
    }

    #[Test]
    public function itCanBeCreatedFromDatabaseRow(): void
    {
        $result = PassRateTrendResult::fromDatabaseRow([
            'date' => '2025-07-01',
            'pass_count' => '42',
            'fail_count' => '3',
        ]);

        self::assertSame('2025-07-01', $result->date);
        self::assertSame(42, $result->passCount);
        self::assertSame(3, $result->failCount);
    }
}

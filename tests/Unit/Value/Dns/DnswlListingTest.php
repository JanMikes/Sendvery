<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DnswlListing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DnswlListingTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function trustLevels(): iterable
    {
        yield 'the list has no confidence in its own entry' => [DnswlListing::TRUST_NONE, false];
        yield 'a legacy low-confidence entry' => [DnswlListing::TRUST_LOW, false];
        yield 'medium confidence' => [DnswlListing::TRUST_MEDIUM, true];
        yield 'high confidence' => [DnswlListing::TRUST_HIGH, true];
    }

    #[Test]
    #[DataProvider('trustLevels')]
    public function onlyAConfidentListingCorroboratesAnything(int $trustLevel, bool $expected): void
    {
        self::assertSame($expected, new DnswlListing($trustLevel, 2)->isTrusted());
    }

    #[Test]
    public function keepsTheCategoryTheListAssigned(): void
    {
        self::assertSame(2, new DnswlListing(DnswlListing::TRUST_HIGH, 2)->category);
    }
}

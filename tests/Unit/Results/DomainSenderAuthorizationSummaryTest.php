<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainSenderAuthorizationSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainSenderAuthorizationSummaryTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $summary = DomainSenderAuthorizationSummary::fromDatabaseRow([
            'authorized_count' => '5',
            'unknown_count' => '3',
            'unique_ip_count' => '8',
        ]);

        self::assertSame(5, $summary->authorizedCount);
        self::assertSame(3, $summary->unknownCount);
        self::assertSame(8, $summary->uniqueIpCount);
    }

    #[Test]
    public function fromDatabaseRowAcceptsIntegers(): void
    {
        $summary = DomainSenderAuthorizationSummary::fromDatabaseRow([
            'authorized_count' => 0,
            'unknown_count' => 0,
            'unique_ip_count' => 0,
        ]);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->unknownCount);
        self::assertSame(0, $summary->uniqueIpCount);
    }
}

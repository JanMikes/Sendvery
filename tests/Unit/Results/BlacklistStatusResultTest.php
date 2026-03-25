<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\BlacklistStatusResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlacklistStatusResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $results = [
            'zen.spamhaus.org' => ['listed' => true, 'reason' => 'Spam source'],
            'b.barracudacentral.org' => ['listed' => false, 'reason' => null],
        ];

        $row = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'ip_address' => '1.2.3.4',
            'checked_at' => '2026-03-25 10:00:00',
            'results' => json_encode($results),
            'is_listed' => true,
        ];

        $result = BlacklistStatusResult::fromDatabaseRow($row);

        self::assertSame('1.2.3.4', $result->ipAddress);
        self::assertTrue($result->isListed);
        self::assertSame(1, $result->listedCount());
        self::assertSame($results, $result->results);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\SenderInventoryResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SenderInventoryResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $row = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'source_ip' => '1.2.3.4',
            'hostname' => 'mail.google.com',
            'organization' => 'Google',
            'label' => null,
            'is_authorized' => true,
            'first_seen_at' => '2026-01-01 00:00:00',
            'last_seen_at' => '2026-03-25 00:00:00',
            'total_messages' => 1000,
            'pass_rate' => 95.5,
        ];

        $result = SenderInventoryResult::fromDatabaseRow($row);

        self::assertSame('1.2.3.4', $result->sourceIp);
        self::assertSame('mail.google.com', $result->hostname);
        self::assertSame('Google', $result->organization);
        self::assertNull($result->label);
        self::assertTrue($result->isAuthorized);
        self::assertSame(1000, $result->totalMessages);
        self::assertSame(95.5, $result->passRate);
    }
}

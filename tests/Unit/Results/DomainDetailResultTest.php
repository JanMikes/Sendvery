<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainDetailResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainDetailResultTest extends TestCase
{
    #[Test]
    public function itCanBeConstructed(): void
    {
        $result = new DomainDetailResult(
            domainId: 'abc-123',
            domainName: 'example.com',
            dmarcPolicy: 'reject',
            isVerified: true,
            createdAt: '2025-01-01 00:00:00',
            totalReports: 10,
            totalMessages: 5000,
            passRate: 98.5,
            uniqueSenders: 15,
        );

        self::assertSame('abc-123', $result->domainId);
        self::assertSame('example.com', $result->domainName);
        self::assertSame('reject', $result->dmarcPolicy);
        self::assertTrue($result->isVerified);
        self::assertSame(10, $result->totalReports);
        self::assertSame(5000, $result->totalMessages);
        self::assertSame(98.5, $result->passRate);
        self::assertSame(15, $result->uniqueSenders);
    }

    #[Test]
    public function itCanBeCreatedFromDatabaseRow(): void
    {
        $result = DomainDetailResult::fromDatabaseRow([
            'domain_id' => 'abc-123',
            'domain_name' => 'test.com',
            'dmarc_policy' => 'none',
            'is_verified' => false,
            'created_at' => '2025-06-01 12:00:00',
            'total_reports' => '3',
            'total_messages' => '100',
            'pass_rate' => '87.5',
            'unique_senders' => '7',
        ]);

        self::assertSame('abc-123', $result->domainId);
        self::assertSame('test.com', $result->domainName);
        self::assertSame('none', $result->dmarcPolicy);
        self::assertFalse($result->isVerified);
        self::assertSame(3, $result->totalReports);
        self::assertSame(100, $result->totalMessages);
        self::assertSame(87.5, $result->passRate);
        self::assertSame(7, $result->uniqueSenders);
    }
}

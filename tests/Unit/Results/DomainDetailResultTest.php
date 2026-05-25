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
            spfVerifiedAt: '2025-01-02 00:00:00',
            dkimVerifiedAt: '2025-01-02 00:00:00',
            dmarcVerifiedAt: '2025-01-02 00:00:00',
            firstReportAt: '2025-01-03 06:00:00',
            createdAt: '2025-01-01 00:00:00',
            totalReports: 10,
            totalMessages: 5000,
            passRate: 98.5,
            uniqueSenders: 15,
            dkimSelector: 'selector1',
        );

        self::assertSame('abc-123', $result->domainId);
        self::assertSame('example.com', $result->domainName);
        self::assertSame('reject', $result->dmarcPolicy);
        self::assertSame('2025-01-02 00:00:00', $result->dmarcVerifiedAt);
        self::assertSame('2025-01-03 06:00:00', $result->firstReportAt);
        self::assertTrue($result->isVerified());
        self::assertSame(10, $result->totalReports);
        self::assertSame(5000, $result->totalMessages);
        self::assertSame(98.5, $result->passRate);
        self::assertSame(15, $result->uniqueSenders);
        self::assertSame('selector1', $result->dkimSelector);
    }

    #[Test]
    public function itCanBeCreatedFromDatabaseRow(): void
    {
        $result = DomainDetailResult::fromDatabaseRow([
            'domain_id' => 'abc-123',
            'domain_name' => 'test.com',
            'dmarc_policy' => 'none',
            'spf_verified_at' => null,
            'dkim_verified_at' => null,
            'dmarc_verified_at' => null,
            'first_report_at' => null,
            'created_at' => '2025-06-01 12:00:00',
            'total_reports' => '3',
            'total_messages' => '100',
            'pass_rate' => '87.5',
            'unique_senders' => '7',
            'dkim_selector' => null,
        ]);

        self::assertSame('abc-123', $result->domainId);
        self::assertSame('test.com', $result->domainName);
        self::assertSame('none', $result->dmarcPolicy);
        self::assertNull($result->dmarcVerifiedAt);
        self::assertFalse($result->isVerified());
        self::assertSame(3, $result->totalReports);
        self::assertSame(100, $result->totalMessages);
        self::assertSame(87.5, $result->passRate);
        self::assertSame(7, $result->uniqueSenders);
        self::assertNull($result->dkimSelector);
    }
}

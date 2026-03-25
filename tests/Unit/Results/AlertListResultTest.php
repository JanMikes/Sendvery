<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\AlertListResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertListResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $result = AlertListResult::fromDatabaseRow([
            'alert_id' => '550e8400-e29b-41d4-a716-446655440000',
            'type' => 'dns_record_changed',
            'severity' => 'warning',
            'title' => 'SPF record changed',
            'message' => 'The SPF record was modified.',
            'is_read' => false,
            'created_at' => '2026-03-25 10:00:00',
            'domain_id' => '660e8400-e29b-41d4-a716-446655440000',
            'domain_name' => 'example.com',
        ]);

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $result->alertId);
        self::assertSame('dns_record_changed', $result->type);
        self::assertSame('warning', $result->severity);
        self::assertSame('SPF record changed', $result->title);
        self::assertFalse($result->isRead);
        self::assertSame('example.com', $result->domainName);
    }

    #[Test]
    public function nullableDomainFields(): void
    {
        $result = AlertListResult::fromDatabaseRow([
            'alert_id' => '550e8400-e29b-41d4-a716-446655440000',
            'type' => 'mailbox_connection_error',
            'severity' => 'warning',
            'title' => 'Connection failed',
            'message' => 'Error message.',
            'is_read' => true,
            'created_at' => '2026-03-25 10:00:00',
            'domain_id' => null,
            'domain_name' => null,
        ]);

        self::assertNull($result->domainId);
        self::assertNull($result->domainName);
        self::assertTrue($result->isRead);
    }
}

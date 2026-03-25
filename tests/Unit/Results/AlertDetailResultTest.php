<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\AlertDetailResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertDetailResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $result = AlertDetailResult::fromDatabaseRow([
            'alert_id' => '550e8400-e29b-41d4-a716-446655440000',
            'type' => 'failure_spike',
            'severity' => 'critical',
            'title' => 'Failure spike detected',
            'message' => 'Spike details.',
            'data' => '{"current_fail_rate": 45.2, "average_fail_rate": 5.1}',
            'is_read' => false,
            'created_at' => '2026-03-25 10:00:00',
            'domain_id' => '660e8400-e29b-41d4-a716-446655440000',
            'domain_name' => 'example.com',
        ]);

        self::assertSame('failure_spike', $result->type);
        self::assertSame('critical', $result->severity);
        self::assertSame(45.2, $result->data['current_fail_rate']);
        self::assertSame(5.1, $result->data['average_fail_rate']);
    }
}

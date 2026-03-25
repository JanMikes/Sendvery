<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainHealthSnapshotResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainHealthSnapshotResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $row = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'grade' => 'B',
            'score' => 82,
            'spf_score' => 85,
            'dkim_score' => 80,
            'dmarc_score' => 75,
            'mx_score' => 90,
            'blacklist_score' => 100,
            'checked_at' => '2026-03-25 10:00:00',
            'recommendations' => '["Upgrade DKIM key"]',
            'share_hash' => 'abc123',
        ];

        $result = DomainHealthSnapshotResult::fromDatabaseRow($row);

        self::assertSame('B', $result->grade);
        self::assertSame(82, $result->score);
        self::assertSame(85, $result->spfScore);
        self::assertSame('abc123', $result->shareHash);
        self::assertSame(['Upgrade DKIM key'], $result->recommendations);
    }

    #[Test]
    public function gradeColor(): void
    {
        $row = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'grade' => 'A',
            'score' => 95,
            'spf_score' => 100,
            'dkim_score' => 100,
            'dmarc_score' => 90,
            'mx_score' => 100,
            'blacklist_score' => 100,
            'checked_at' => '2026-03-25 10:00:00',
            'recommendations' => '[]',
            'share_hash' => null,
        ];

        $result = DomainHealthSnapshotResult::fromDatabaseRow($row);
        self::assertSame('text-success', $result->gradeColor());
    }
}

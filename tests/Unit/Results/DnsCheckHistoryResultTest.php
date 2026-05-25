<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DnsCheckHistoryResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DnsCheckHistoryResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $result = DnsCheckHistoryResult::fromDatabaseRow([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'type' => 'spf',
            'checked_at' => '2026-03-25 03:00:00',
            'raw_record' => 'v=spf1 ~all',
            'is_valid' => true,
            'issues' => '[]',
            'details' => '{"lookup_count": 3}',
            'previous_raw_record' => 'v=spf1 -all',
            'has_changed' => true,
            'is_initial_check' => false,
        ]);

        self::assertSame('spf', $result->type);
        self::assertSame('v=spf1 ~all', $result->rawRecord);
        self::assertTrue($result->isValid);
        self::assertSame([], $result->issues);
        self::assertSame(3, $result->details['lookup_count']);
        self::assertSame('v=spf1 -all', $result->previousRawRecord);
        self::assertTrue($result->hasChanged);
        self::assertFalse($result->isInitialCheck);
        self::assertTrue($result->isRealChange());
    }

    #[Test]
    public function nullRecords(): void
    {
        $result = DnsCheckHistoryResult::fromDatabaseRow([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'type' => 'dmarc',
            'checked_at' => '2026-03-25 03:00:00',
            'raw_record' => null,
            'is_valid' => false,
            'issues' => '[{"severity":"critical","message":"No DMARC record found"}]',
            'details' => '{}',
            'previous_raw_record' => null,
            'has_changed' => false,
            'is_initial_check' => true,
        ]);

        self::assertNull($result->rawRecord);
        self::assertNull($result->previousRawRecord);
        self::assertFalse($result->isValid);
        self::assertCount(1, $result->issues);
        self::assertSame('critical', $result->issues[0]['severity']);
        self::assertTrue($result->isInitialCheck);
    }

    #[Test]
    public function initialCheckRowIsNotARealChangeEvenWhenHasChangedIsTrue(): void
    {
        // The DnsMonitor service writes the first row per (domain, type) with
        // `hasChanged=true` because previous_raw_record is NULL and that's
        // !== a value. TASK-125 splits that into a distinct semantic so the
        // template renders INITIAL CHECK instead of CHANGED. `isRealChange()`
        // encapsulates the new rule: a real change requires a prior baseline.
        $result = DnsCheckHistoryResult::fromDatabaseRow([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'type' => 'dmarc',
            'checked_at' => '2026-03-25 03:00:00',
            'raw_record' => 'v=DMARC1; p=none',
            'is_valid' => true,
            'issues' => '[]',
            'details' => '{}',
            'previous_raw_record' => null,
            'has_changed' => true,
            'is_initial_check' => true,
        ]);

        self::assertTrue($result->hasChanged);
        self::assertTrue($result->isInitialCheck);
        self::assertFalse(
            $result->isRealChange(),
            'A baseline row must never count as a real change — that is the trust-eroding bug TASK-125 fixes.',
        );
    }
}

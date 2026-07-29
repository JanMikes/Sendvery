<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\BlacklistStatusResult;
use App\Value\BlacklistListingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlacklistStatusResultTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $results */
    private static function row(array $results, bool $isListed): BlacklistStatusResult
    {
        return BlacklistStatusResult::fromDatabaseRow([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'ip_address' => '1.2.3.4',
            'checked_at' => '2026-03-25 10:00:00',
            'results' => json_encode($results, JSON_THROW_ON_ERROR),
            'is_listed' => $isListed,
        ]);
    }

    #[Test]
    public function readsPerListVerdictsFromTheStoredRow(): void
    {
        $result = self::row([
            'zen.spamhaus.org' => ['status' => 'listed', 'listed' => true, 'reason' => 'Spam source', 'return_code' => '127.0.0.2'],
            'b.barracudacentral.org' => ['status' => 'not_listed', 'listed' => false, 'reason' => null, 'return_code' => null],
        ], true);

        self::assertSame('1.2.3.4', $result->ipAddress);
        self::assertTrue($result->isListed);
        self::assertSame(1, $result->listedCount());
        self::assertSame(2, $result->answeredCount());
        self::assertSame('zen.spamhaus.org', $result->listings[0]->dnsbl);
        self::assertSame('127.0.0.2', $result->listings[0]->returnCode);
    }

    #[Test]
    public function aStoredUnansweredQueryIsNeitherListedNorClean(): void
    {
        $result = self::row([
            'zen.spamhaus.org' => ['status' => 'check_failed', 'listed' => false, 'reason' => 'Error: open resolver', 'return_code' => '127.255.255.254'],
            'b.barracudacentral.org' => ['status' => 'not_listed', 'listed' => false, 'reason' => null, 'return_code' => null],
        ], false);

        self::assertSame(0, $result->listedCount());
        self::assertSame(1, $result->unavailableCount());
        self::assertSame(1, $result->answeredCount());
        self::assertFalse($result->isInconclusive());
        self::assertSame(BlacklistListingStatus::CheckFailed, $result->listings[0]->status);
    }

    #[Test]
    public function anIpWhereNoListAnsweredIsReportedAsInconclusive(): void
    {
        $result = self::row([
            'zen.spamhaus.org' => ['status' => 'check_failed', 'listed' => false, 'reason' => null, 'return_code' => '127.255.255.254'],
            'cbl.abuseat.org' => ['status' => 'check_failed', 'listed' => false, 'reason' => null, 'return_code' => '127.255.255.254'],
        ], false);

        self::assertTrue($result->isInconclusive());
        self::assertSame(0, $result->answeredCount());
    }

    #[Test]
    public function rowsWrittenBeforeTheThreeStateModelStillRead(): void
    {
        // Pre-existing production rows carry only `listed`. They must keep
        // rendering rather than blowing up or silently becoming "unknown".
        $result = self::row([
            'zen.spamhaus.org' => ['listed' => true, 'reason' => 'Spam source'],
            'b.barracudacentral.org' => ['listed' => false, 'reason' => null],
        ], true);

        self::assertSame(1, $result->listedCount());
        self::assertSame(2, $result->answeredCount());
        self::assertSame(BlacklistListingStatus::Listed, $result->listings[0]->status);
        self::assertSame(BlacklistListingStatus::NotListed, $result->listings[1]->status);
        self::assertNull($result->listings[0]->returnCode);
    }

    #[Test]
    public function aRowWithNeitherStatusNorListedIsTreatedAsUnknown(): void
    {
        // Defensive: a truncated or hand-edited row must not default to
        // "listed" (a false alarm) or "clean" (a false all-clear).
        $result = self::row(['zen.spamhaus.org' => ['reason' => null]], false);

        self::assertSame(BlacklistListingStatus::CheckFailed, $result->listings[0]->status);
        self::assertTrue($result->isInconclusive());
    }

    #[Test]
    public function anUnrecognisedStatusValueIsTreatedAsUnknown(): void
    {
        // A status written by a newer version than the one reading it.
        $result = self::row(['zen.spamhaus.org' => ['status' => 'something_new', 'listed' => false]], false);

        self::assertSame(BlacklistListingStatus::CheckFailed, $result->listings[0]->status);
    }
}

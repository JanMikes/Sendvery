<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\BlacklistListing;
use App\Value\BlacklistListingStatus;
use App\Value\BlacklistResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlacklistResultTest extends TestCase
{
    #[Test]
    public function anIpOnRealBlocklistsIsReportedAsListed(): void
    {
        $result = new BlacklistResult('1.2.3.4', [
            new BlacklistListing('zen.spamhaus.org', BlacklistListingStatus::Listed, 'Spam source', '127.0.0.2'),
            new BlacklistListing('b.barracudacentral.org', BlacklistListingStatus::NotListed),
            new BlacklistListing('psbl.surriel.com', BlacklistListingStatus::Listed, null, '127.0.0.5'),
        ]);

        self::assertSame('1.2.3.4', $result->ipAddress);
        self::assertTrue($result->isListed());
        self::assertSame(2, $result->listedCount());
        self::assertSame(3, $result->totalChecked());
        self::assertSame(3, $result->answeredCount());
        self::assertFalse($result->isInconclusive());
    }

    #[Test]
    public function anIpNoBlocklistKnowsAboutIsReportedAsClean(): void
    {
        $result = new BlacklistResult('5.6.7.8', [
            new BlacklistListing('zen.spamhaus.org', BlacklistListingStatus::NotListed),
            new BlacklistListing('b.barracudacentral.org', BlacklistListingStatus::NotListed),
        ]);

        self::assertFalse($result->isListed());
        self::assertSame(0, $result->listedCount());
        self::assertSame(2, $result->answeredCount());
        self::assertFalse($result->isInconclusive());
    }

    #[Test]
    public function aBlocklistThatRefusedTheQueryIsNeverCountedAsAListing(): void
    {
        // The production incident: Spamhaus answered every query with an
        // open-resolver error code, and each one was reported as a listing.
        $result = new BlacklistResult('77.75.78.89', [
            new BlacklistListing('zen.spamhaus.org', BlacklistListingStatus::CheckFailed, 'Error: open resolver', '127.255.255.254'),
            new BlacklistListing('cbl.abuseat.org', BlacklistListingStatus::CheckFailed, 'Error: open resolver', '127.255.255.254'),
            new BlacklistListing('b.barracudacentral.org', BlacklistListingStatus::NotListed),
        ]);

        self::assertFalse($result->isListed(), 'A refused query must never raise a blacklist alert.');
        self::assertSame(0, $result->listedCount());
        self::assertSame(2, $result->unavailableCount());
        self::assertSame(1, $result->answeredCount());
    }

    #[Test]
    public function anIpNoBlocklistCouldBeAskedAboutIsInconclusiveRatherThanClean(): void
    {
        $result = new BlacklistResult('77.75.78.89', [
            new BlacklistListing('zen.spamhaus.org', BlacklistListingStatus::CheckFailed, 'Error: open resolver', '127.255.255.254'),
            new BlacklistListing('cbl.abuseat.org', BlacklistListingStatus::CheckFailed, 'Error: open resolver', '127.255.255.254'),
        ]);

        self::assertFalse($result->isListed());
        self::assertTrue(
            $result->isInconclusive(),
            'With no list answering, reporting "clean" would be a false all-clear.',
        );
        self::assertSame(0, $result->answeredCount());
    }

    #[Test]
    public function listedOnNamesOnlyTheListsThatActuallyAnsweredWithAListing(): void
    {
        $result = new BlacklistResult('1.2.3.4', [
            new BlacklistListing('zen.spamhaus.org', BlacklistListingStatus::CheckFailed, 'Error: open resolver', '127.255.255.254'),
            new BlacklistListing('bl.spamcop.net', BlacklistListingStatus::Listed, null, '127.0.0.2'),
        ]);

        self::assertSame(
            ['bl.spamcop.net'],
            array_map(static fn (BlacklistListing $l): string => $l->dnsbl, $result->listedOn()),
        );
    }

    #[Test]
    public function storageRoundTripPreservesTheThreeStates(): void
    {
        $result = new BlacklistResult('1.2.3.4', [
            new BlacklistListing('zen.spamhaus.org', BlacklistListingStatus::Listed, 'Spam source', '127.0.0.2'),
            new BlacklistListing('bl.spamcop.net', BlacklistListingStatus::NotListed),
            new BlacklistListing('cbl.abuseat.org', BlacklistListingStatus::CheckFailed, 'Error: open resolver', '127.255.255.254'),
        ]);

        $stored = $result->toStorageArray();

        self::assertSame('listed', $stored['zen.spamhaus.org']['status']);
        self::assertTrue($stored['zen.spamhaus.org']['listed']);
        self::assertSame('not_listed', $stored['bl.spamcop.net']['status']);
        self::assertFalse($stored['bl.spamcop.net']['listed']);
        self::assertSame('check_failed', $stored['cbl.abuseat.org']['status']);
        self::assertFalse(
            $stored['cbl.abuseat.org']['listed'],
            'The legacy `listed` key must stay false for an unanswered query, or old consumers re-create the bug.',
        );
        self::assertSame('127.255.255.254', $stored['cbl.abuseat.org']['return_code']);
    }
}

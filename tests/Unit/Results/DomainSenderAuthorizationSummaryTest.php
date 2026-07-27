<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainSenderAuthorizationSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainSenderAuthorizationSummaryTest extends TestCase
{
    #[Test]
    public function fromDatabaseRow(): void
    {
        $summary = DomainSenderAuthorizationSummary::fromDatabaseRow([
            'authorized_count' => '5',
            'needs_review_count' => '3',
            'not_authorized_count' => '2',
            'unique_ip_count' => '10',
            'needs_review_messages' => '1400',
        ]);

        self::assertSame(5, $summary->authorizedCount);
        self::assertSame(3, $summary->needsReviewCount);
        self::assertSame(2, $summary->notAuthorizedCount);
        self::assertSame(10, $summary->uniqueIpCount);
        self::assertSame(1400, $summary->needsReviewMessages);
    }

    #[Test]
    public function fromDatabaseRowAcceptsIntegers(): void
    {
        $summary = DomainSenderAuthorizationSummary::fromDatabaseRow([
            'authorized_count' => 0,
            'needs_review_count' => 0,
            'not_authorized_count' => 0,
            'unique_ip_count' => 0,
            'needs_review_messages' => 0,
        ]);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->needsReviewCount);
        self::assertSame(0, $summary->notAuthorizedCount);
        self::assertSame(0, $summary->uniqueIpCount);
        self::assertSame(0, $summary->needsReviewMessages);
    }

    /**
     * The legacy `?filter=unauthorized` axis spans BOTH non-authorized states,
     * so the count backing it has to be their sum — anything else would print a
     * different number than the filtered list returns.
     */
    #[Test]
    public function everythingNotAuthorizedCoversBothUnreviewedAndRejectedSenders(): void
    {
        $summary = new DomainSenderAuthorizationSummary(
            authorizedCount: 4,
            needsReviewCount: 3,
            notAuthorizedCount: 2,
            uniqueIpCount: 9,
            needsReviewMessages: 120,
        );

        self::assertSame(5, $summary->unauthorizedCount());
    }

    #[Test]
    public function aDomainWithNoDiscoveredSendersReportsNothingToShow(): void
    {
        $summary = new DomainSenderAuthorizationSummary(0, 0, 0, 0, 0);

        self::assertFalse($summary->hasAnySenders());
    }

    /**
     * `known_sender` rows can exist before any DMARC record does (import, seed),
     * so the stat row keys off "is there anything at all to count", not off
     * report volume.
     */
    #[Test]
    public function aDomainWithAnySenderAtAllHasSomethingToShow(): void
    {
        self::assertTrue((new DomainSenderAuthorizationSummary(1, 0, 0, 0, 0))->hasAnySenders());
        self::assertTrue((new DomainSenderAuthorizationSummary(0, 1, 0, 0, 0))->hasAnySenders());
        self::assertTrue((new DomainSenderAuthorizationSummary(0, 0, 1, 0, 0))->hasAnySenders());
        self::assertTrue((new DomainSenderAuthorizationSummary(0, 0, 0, 1, 0))->hasAnySenders());
    }
}

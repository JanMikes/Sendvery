<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\WeeklyDigestSenderReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestSenderReviewTest extends TestCase
{
    #[Test]
    public function aDomainWithNothingAwaitingReviewRendersNoSection(): void
    {
        $review = WeeklyDigestSenderReview::none();

        self::assertSame(0, $review->needsReviewCount);
        self::assertSame(0, $review->needsReviewMessages);
        self::assertSame([], $review->topSenderNames);
        self::assertSame(0, $review->distinctNameCount);
        self::assertFalse($review->hasAny());
    }

    #[Test]
    public function carriesTheCountVolumeAndNamesTheEmailPrints(): void
    {
        $review = new WeeklyDigestSenderReview(3, 1250, ['Seznam', 'Mailchimp'], 2);

        self::assertTrue($review->hasAny());
        self::assertSame(3, $review->needsReviewCount);
        self::assertSame(1250, $review->needsReviewMessages);
        self::assertSame(['Seznam', 'Mailchimp'], $review->topSenderNames);
    }

    /**
     * The named senders are a sample, capped so one chatty domain cannot push
     * the rest of the digest off screen — the "+N more" tail has to be honest
     * about what it is hiding.
     */
    #[Test]
    public function namesAreASampleAndTheRemainderIsReportedAsMore(): void
    {
        $review = new WeeklyDigestSenderReview(9, 400, ['a', 'b', 'c', 'd', 'e'], 9);

        self::assertTrue($review->hasMoreThanNamed());
        self::assertSame(4, $review->unnamedCount());
    }

    #[Test]
    public function everySenderNamedMeansNoMoreTail(): void
    {
        $review = new WeeklyDigestSenderReview(2, 400, ['a', 'b'], 2);

        self::assertFalse($review->hasMoreThanNamed());
        self::assertSame(0, $review->unnamedCount());
    }

    /**
     * A provider sending from five machines is one chip, not five. The tail
     * counts names so it cannot claim to be hiding four senders that are all
     * the same service the reader can already see.
     */
    #[Test]
    public function severalAddressesBelongingToOneProviderDoNotManufactureAMoreTail(): void
    {
        $review = new WeeklyDigestSenderReview(5, 900, ['Seznam'], 1);

        self::assertFalse($review->hasMoreThanNamed());
        self::assertSame(0, $review->unnamedCount());
    }
}

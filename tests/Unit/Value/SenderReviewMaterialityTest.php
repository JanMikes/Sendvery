<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SenderReviewMateriality;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * When is a pile of unreviewed senders worth an email of its own?
 *
 * The answer must be about volume, not count — otherwise the reminder fires on
 * a handful of one-message forwarder blips and gets filtered to trash before it
 * ever reports something real.
 */
final class SenderReviewMaterialityTest extends TestCase
{
    #[Test]
    public function oneUnreviewedSenderWithSubstantialHistoryIsWorthAnEmail(): void
    {
        self::assertTrue(SenderReviewMateriality::isMaterial(
            largestSenderMessages: 50,
            unreviewedMessages: 50,
            domainMessages: 100000,
        ));
    }

    #[Test]
    public function tenSendersThatEachDeliveredOneMessageAreNotWorthAnEmail(): void
    {
        self::assertFalse(SenderReviewMateriality::isMaterial(
            largestSenderMessages: 1,
            unreviewedMessages: 10,
            domainMessages: 5000,
        ));
    }

    /**
     * The swarm case the single-sender test misses: many small unreviewed
     * senders that together carry a real share of the domain's mail.
     */
    #[Test]
    public function manySmallSendersCarryingARealShareOfTheDomainsMailAreWorthAnEmail(): void
    {
        self::assertTrue(SenderReviewMateriality::isMaterial(
            largestSenderMessages: 8,
            unreviewedMessages: 40,
            domainMessages: 400,
        ));
    }

    #[Test]
    public function aTinyShareOfALoudDomainIsNotWorthAnEmail(): void
    {
        self::assertFalse(SenderReviewMateriality::isMaterial(
            largestSenderMessages: 8,
            unreviewedMessages: 40,
            domainMessages: 100000,
        ));
    }

    #[Test]
    public function volumeBelowTheShareFloorNeverQualifiesHoweverQuietTheDomainIs(): void
    {
        // 19 of 19 messages is a 100% share, but 19 messages is not evidence of
        // anything worth interrupting somebody for.
        self::assertFalse(SenderReviewMateriality::isMaterial(
            largestSenderMessages: 19,
            unreviewedMessages: 19,
            domainMessages: 19,
        ));
    }

    #[Test]
    public function aDomainWithNoRecordedVolumeHasNoShareAndTriggersNothing(): void
    {
        self::assertSame(0.0, SenderReviewMateriality::sharePercent(30, 0));
        self::assertFalse(SenderReviewMateriality::isMaterial(
            largestSenderMessages: 30,
            unreviewedMessages: 30,
            domainMessages: 0,
        ));
    }

    #[Test]
    public function sharePercentReportsTheProportionOfMailNobodyHasVouchedFor(): void
    {
        self::assertSame(25.0, SenderReviewMateriality::sharePercent(250, 1000));
    }
}

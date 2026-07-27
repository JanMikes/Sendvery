<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SenderRole;
use App\Value\WeeklyDigestDomainData;
use App\Value\WeeklyDigestNewSender;
use App\Value\WeeklyDigestSenderReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestDomainDataTest extends TestCase
{
    #[Test]
    public function carriesTheWeeksNumbersForOneDomain(): void
    {
        $domain = new WeeklyDigestDomainData(
            domainName: 'example.com',
            totalMessages: 250,
            passedMessages: 246,
            passRate: 98.2,
            passRateDelta: -1.5,
            newSenders: [
                new WeeklyDigestNewSender('mailchimp.com', SenderRole::Esp, 40, 40),
                new WeeklyDigestNewSender('sendgrid.net', SenderRole::Esp, 12, 12),
            ],
            domainId: '00000000-0000-0000-0000-000000000001',
            senderReview: WeeklyDigestSenderReview::none(),
        );

        self::assertSame('example.com', $domain->domainName);
        self::assertSame(250, $domain->totalMessages);
        self::assertSame(246, $domain->passedMessages);
        self::assertSame(98.2, $domain->passRate);
        self::assertSame(-1.5, $domain->passRateDelta);
        self::assertSame(
            ['mailchimp.com', 'sendgrid.net'],
            array_map(static fn (WeeklyDigestNewSender $s): string => $s->label, $domain->newSenders),
        );
    }

    #[Test]
    public function aDomainWithMeasuredTrafficReportsThatItHasPassRateData(): void
    {
        $domain = new WeeklyDigestDomainData(
            domainName: 'example.com',
            totalMessages: 250,
            passedMessages: 246,
            passRate: 98.2,
            passRateDelta: null,
            newSenders: [],
            domainId: '00000000-0000-0000-0000-000000000001',
            senderReview: WeeklyDigestSenderReview::none(),
        );

        self::assertTrue(
            $domain->hasPassRateData(),
            'A domain with a measured pass rate must report that it has data to show.',
        );
    }

    #[Test]
    public function aDomainThatReceivedNothingHasNoPassRateRatherThanZero(): void
    {
        // The digest must be able to say "waiting for first report" instead of
        // printing 0%, which reads as "every message failed".
        $domain = new WeeklyDigestDomainData(
            domainName: 'brand-new.com',
            totalMessages: 0,
            passedMessages: 0,
            passRate: null,
            passRateDelta: null,
            newSenders: [],
            domainId: '00000000-0000-0000-0000-000000000001',
            senderReview: WeeklyDigestSenderReview::none(),
        );

        self::assertNull($domain->passRate, 'No reports must mean no pass rate, never 0.0.');
        self::assertFalse(
            $domain->hasPassRateData(),
            'A domain with no reports must not claim to have a pass rate worth printing.',
        );
    }

    #[Test]
    public function aDomainWithNoComparablePreviousWeekHasNoTrend(): void
    {
        $domain = new WeeklyDigestDomainData(
            domainName: 'new-domain.com',
            totalMessages: 10,
            passedMessages: 10,
            passRate: 100.0,
            passRateDelta: null,
            newSenders: [],
            domainId: '00000000-0000-0000-0000-000000000001',
            senderReview: WeeklyDigestSenderReview::none(),
        );

        self::assertNull($domain->passRateDelta);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SenderRole;
use App\Value\WeeklyDigestNewSender;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestNewSenderTest extends TestCase
{
    #[Test]
    public function carriesTheGroupedNameTheRoleAndTheVolumeBehindIt(): void
    {
        $sender = new WeeklyDigestNewSender(
            label: 'seznam.cz',
            role: SenderRole::OwnRelay,
            messageCount: 47,
            passedMessageCount: 47,
        );

        self::assertSame('seznam.cz', $sender->label);
        self::assertSame(SenderRole::OwnRelay, $sender->role);
        self::assertSame(47, $sender->messageCount);
        self::assertSame(100.0, $sender->passRate());
    }

    /**
     * A recipient-side gateway breaks SPF by design, so the digest has to be
     * able to print the real rate next to the role that explains it.
     */
    #[Test]
    public function reportsThePassRateOfWhatTheSenderActuallySent(): void
    {
        $sender = new WeeklyDigestNewSender(
            label: 'cloud-sec-av.com',
            role: SenderRole::Forwarder,
            messageCount: 4,
            passedMessageCount: 1,
        );

        self::assertSame(25.0, $sender->passRate());
    }

    #[Test]
    public function aSenderCreditedWithNoMessagesHasNoPassRateRatherThanZeroPercent(): void
    {
        // Reporters are allowed to describe a row carrying no messages. Printing
        // 0% there would accuse a sender of failing mail it never sent.
        $sender = new WeeklyDigestNewSender(
            label: '192.0.2.10',
            role: SenderRole::Unknown,
            messageCount: 0,
            passedMessageCount: 0,
        );

        self::assertNull($sender->passRate());
    }
}

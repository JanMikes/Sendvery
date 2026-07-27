<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SenderAuthSignals;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SenderAuthSignalsTest extends TestCase
{
    #[Test]
    public function keepsTheRatesAndVolumeItWasGiven(): void
    {
        $signals = new SenderAuthSignals(
            dkimPassRate: 100.0,
            spfPassRate: 0.0,
            isAuthorized: true,
            totalMessages: 7,
        );

        self::assertSame(100.0, $signals->dkimPassRate);
        self::assertSame(0.0, $signals->spfPassRate);
        self::assertTrue($signals->isAuthorized);
        self::assertSame(7, $signals->totalMessages);
    }

    #[Test]
    public function derivesPercentagesFromRawMessageCounts(): void
    {
        $signals = SenderAuthSignals::fromCounts(dkimPassed: 8, spfPassed: 2, totalMessages: 10);

        self::assertSame(80.0, $signals->dkimPassRate);
        self::assertSame(20.0, $signals->spfPassRate);
        self::assertSame(10, $signals->totalMessages);
        self::assertFalse($signals->isAuthorized, 'A sender is unauthorized until a human says otherwise.');
    }

    #[Test]
    public function reportsZeroRatesForASenderWithNoMessagesInsteadOfDividingByZero(): void
    {
        $signals = SenderAuthSignals::fromCounts(dkimPassed: 0, spfPassed: 0, totalMessages: 0);

        self::assertSame(0.0, $signals->dkimPassRate);
        self::assertSame(0.0, $signals->spfPassRate);
    }

    #[Test]
    public function carriesTheAuthorizationDecisionThrough(): void
    {
        $signals = SenderAuthSignals::fromCounts(
            dkimPassed: 5,
            spfPassed: 5,
            totalMessages: 5,
            isAuthorized: true,
        );

        self::assertTrue($signals->isAuthorized);
    }
}

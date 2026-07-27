<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DomainRecheckAvailability;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wait shown on a throttled DNS re-check. The label is user-facing copy
 * ("Re-check available in 2m"), so it must never round in the user's
 * disfavour — a label that promises the button back sooner than the limiter
 * releases it produces a second bounced click.
 */
final class DomainRecheckAvailabilityTest extends TestCase
{
    #[Test]
    public function anAvailableRecheckCarriesNoWait(): void
    {
        $availability = DomainRecheckAvailability::available();

        self::assertTrue($availability->isAvailable, 'A domain outside its cooldown may be re-checked.');
        self::assertSame(0, $availability->cooldownSeconds, 'There is nothing to wait for when the re-check is available.');
    }

    #[Test]
    public function aCooldownIsNotAvailableAndKeepsItsRemainingSeconds(): void
    {
        $availability = DomainRecheckAvailability::coolingDown(95);

        self::assertFalse($availability->isAvailable, 'A domain inside its cooldown must not be re-checked.');
        self::assertSame(95, $availability->cooldownSeconds);
    }

    #[Test]
    public function subMinuteWaitsAreShownInSeconds(): void
    {
        self::assertSame('45s', DomainRecheckAvailability::coolingDown(45)->cooldownLabel());
        self::assertSame('59s', DomainRecheckAvailability::coolingDown(59)->cooldownLabel());
    }

    #[Test]
    public function longerWaitsRoundUpToWholeMinutesSoTheButtonIsNeverPromisedEarly(): void
    {
        self::assertSame('1m', DomainRecheckAvailability::coolingDown(60)->cooldownLabel());
        self::assertSame('2m', DomainRecheckAvailability::coolingDown(61)->cooldownLabel(), '61 seconds still means waiting into the second minute, so it reads as 2m rather than 1m.');
        self::assertSame('3m', DomainRecheckAvailability::coolingDown(180)->cooldownLabel());
    }

    #[Test]
    public function aVanishinglyShortWaitStillReadsAsOneSecond(): void
    {
        // The limiter can report a sub-second remainder while the button is
        // still disabled; "0s" would tell the user to wait for nothing.
        self::assertSame('1s', DomainRecheckAvailability::coolingDown(0)->cooldownLabel());
        self::assertSame('1s', DomainRecheckAvailability::coolingDown(-4)->cooldownLabel());
    }
}

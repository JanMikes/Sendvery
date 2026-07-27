<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Reports;

use App\Value\Reports\AuthMechanismOutcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The outcome enum owns the wording and the severity tone shown on the report
 * detail page, so the badge, the label and the pass/fail arithmetic can never
 * drift apart across templates.
 */
final class AuthMechanismOutcomeTest extends TestCase
{
    #[Test]
    public function onlyAnAlignedMechanismCanMakeDmarcPass(): void
    {
        self::assertTrue(AuthMechanismOutcome::Aligned->contributesToDmarcPass());
        self::assertFalse(AuthMechanismOutcome::Misaligned->contributesToDmarcPass());
        self::assertFalse(AuthMechanismOutcome::AuthenticationFailed->contributesToDmarcPass());
        self::assertFalse(AuthMechanismOutcome::NotPresent->contributesToDmarcPass());
    }

    #[Test]
    public function labelsNameTheProblemInPlainLanguage(): void
    {
        self::assertSame('Aligned', AuthMechanismOutcome::Aligned->label());
        self::assertSame('Not aligned', AuthMechanismOutcome::Misaligned->label());
        self::assertSame('Check failed', AuthMechanismOutcome::AuthenticationFailed->label());
        self::assertSame('Not present', AuthMechanismOutcome::NotPresent->label());
    }

    #[Test]
    public function alignedIsTheOnlySuccessTone(): void
    {
        self::assertSame('success', AuthMechanismOutcome::Aligned->badgeTone());
        self::assertSame('error', AuthMechanismOutcome::Misaligned->badgeTone());
        self::assertSame('error', AuthMechanismOutcome::AuthenticationFailed->badgeTone());
        self::assertSame('ghost', AuthMechanismOutcome::NotPresent->badgeTone());
    }

    #[Test]
    public function everyCaseHasALabelAndAKnownTone(): void
    {
        // Drift guard: a new case added without extending the match() blows up
        // here instead of rendering an unstyled badge in production.
        foreach (AuthMechanismOutcome::cases() as $outcome) {
            self::assertNotSame('', $outcome->label());
            self::assertContains($outcome->badgeTone(), ['success', 'error', 'ghost']);
        }
    }
}

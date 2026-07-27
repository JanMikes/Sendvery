<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SenderReviewState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary contract. Every surface — badge, filter tab, summary chip,
 * bulk button, digest section, reminder email — reads its wording from this
 * enum, so these assertions are what stops the app drifting back into four
 * labels for two states.
 */
final class SenderReviewStateTest extends TestCase
{
    #[Test]
    public function anAuthorizedSenderIsSettledWhateverElseIsKnownAboutIt(): void
    {
        self::assertSame(SenderReviewState::Authorized, SenderReviewState::fromFlags(true, false));
        self::assertSame(SenderReviewState::Authorized, SenderReviewState::fromFlags(true, true));
    }

    #[Test]
    public function aSenderNobodyHasTouchedIsAwaitingReview(): void
    {
        self::assertSame(SenderReviewState::NeedsReview, SenderReviewState::fromFlags(false, false));
    }

    #[Test]
    public function aSenderSomebodyReviewedAndLeftUnauthorizedIsRejected(): void
    {
        self::assertSame(SenderReviewState::NotAuthorized, SenderReviewState::fromFlags(false, true));
    }

    /**
     * Exactly one state is a request to the user. If a second one ever starts
     * claiming to need a decision, the "N senders waiting for your review"
     * count stops matching the filtered list it links to.
     */
    #[Test]
    public function onlyTheUnreviewedStateAsksTheUserForSomething(): void
    {
        self::assertTrue(SenderReviewState::NeedsReview->needsDecision());
        self::assertFalse(SenderReviewState::Authorized->needsDecision());
        self::assertFalse(SenderReviewState::NotAuthorized->needsDecision());
    }

    #[Test]
    public function eachStateHasItsOwnLabelAndSeverityColour(): void
    {
        self::assertSame('Authorized', SenderReviewState::Authorized->label());
        self::assertSame('Needs review', SenderReviewState::NeedsReview->label());
        self::assertSame('Not authorized', SenderReviewState::NotAuthorized->label());

        // Semantic daisyUI tokens: the amber one is the one asking for a
        // decision, red is a sender the user actively rejected.
        self::assertSame('badge-success', SenderReviewState::Authorized->badgeClass());
        self::assertSame('badge-warning', SenderReviewState::NeedsReview->badgeClass());
        self::assertSame('badge-error', SenderReviewState::NotAuthorized->badgeClass());
    }

    /**
     * The badge's tooltip. The whole ticket exists because an amber pill with
     * no explanation left the user unable to tell whether action was expected,
     * so every state owes the reader a sentence.
     */
    #[Test]
    public function everyStateExplainsItselfInPlainLanguage(): void
    {
        foreach (SenderReviewState::cases() as $state) {
            self::assertNotSame('', $state->meaning(), $state->value.' must explain what it means.');
        }

        self::assertStringContainsString('Nothing to do', SenderReviewState::Authorized->meaning());
        self::assertStringContainsString('nobody has decided', SenderReviewState::NeedsReview->meaning());
        self::assertStringContainsString('not yours', SenderReviewState::NotAuthorized->meaning());
    }
}

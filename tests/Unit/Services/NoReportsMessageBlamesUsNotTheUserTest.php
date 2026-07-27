<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\NoReportsExplanation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * When a domain has published DMARC and no reports have arrived, exactly one
 * question decides what the user should be told: is OUR side working?
 *
 * The message shipped as an unconditional accusation — "no reports have arrived
 * after 48 hours, check that the rua= tag points at Sendvery" — with nothing
 * behind it but the absence of reports. It could not distinguish a genuinely
 * misconfigured rua= tag from our own poller being an hour behind, so a user
 * whose DNS was already correct was sent to re-check it. That is the most
 * expensive kind of false alarm: it costs the user real time and it teaches
 * them the next warning is probably also wrong.
 *
 * Absent proof of our own health, the honest sentence is about us.
 */
final class NoReportsMessageBlamesUsNotTheUserTest extends TestCase
{
    #[Test]
    public function ourOwnPipelineBeingUnprovenIsNotEvidenceAgainstTheUser(): void
    {
        $explanation = NoReportsExplanation::forPipelineHealth(isPipelineProvenHealthy: false);

        self::assertStringNotContainsStringIgnoringCase(
            'rua=',
            $explanation->detail,
            'We cannot ask the user to check their rua= tag when we have no evidence our own poller is running. Their DNS may be perfect and we would be sending them to re-verify it.',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'check that',
            $explanation->detail,
            'An instruction to go and check something is an accusation. Absent proof of our own health it has no basis.',
        );
        self::assertNotSame(
            'error',
            $explanation->tone,
            'Our own ingestion lagging is not the user failing at anything, so it must not wear the error tone on their dashboard.',
        );
    }

    #[Test]
    public function aProvenHealthyPipelineEarnsTheRightToAskAboutRua(): void
    {
        $explanation = NoReportsExplanation::forPipelineHealth(isPipelineProvenHealthy: true);

        self::assertStringContainsStringIgnoringCase(
            'rua=',
            $explanation->detail,
            'Once we can show our own ingestion is healthy, the rua= tag genuinely is the most likely cause and naming it is the useful thing to do.',
        );
    }

    #[Test]
    public function theUnprovenMessageSaysWhoIsWaitingOnWhom(): void
    {
        $explanation = NoReportsExplanation::forPipelineHealth(isPipelineProvenHealthy: false);

        // CLAUDE.md's positive obligation: suppressing the accusation is not
        // enough on its own — a vague replacement reads as broken too. The user
        // must learn whether they have anything to do.
        self::assertNotSame(
            '',
            trim($explanation->detail),
            'A blank where an explanation belongs reads as broken.',
        );
        self::assertStringContainsStringIgnoringCase(
            'nothing',
            $explanation->detail,
            'The user must be told explicitly that there is nothing for them to do, or an unexplained absence still reads as their fault.',
        );
    }
}

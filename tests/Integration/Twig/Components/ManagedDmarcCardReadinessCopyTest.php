<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Results\ManagedDmarcCardResult;
use App\Tests\WebTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\ManagedDmarcCardState;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/**
 * The readiness line on the managed-DMARC card, rendered against the three
 * shapes its data actually takes.
 *
 * THE DEFECT THIS EXISTS FOR: with no readiness verdict the card received
 * `passRate = 0.0` / `daysOfData = 0` and printed a sentence built out of those
 * invented numbers — "Alignment is 0.0% over 0 days", which is the wording of
 * total authentication failure applied to a domain nobody had measured. Absence
 * now arrives as null and gets its own honest arm, and every real rate goes
 * through the shared `pass_rate_value` macro so this surface cannot drift from
 * the rest of the app's no-data convention.
 */
final class ManagedDmarcCardReadinessCopyTest extends WebTestCase
{
    #[Test]
    public function anUnmeasuredDomainIsToldWhatItIsWaitingForInsteadOfBeingShownAZeroPassRate(): void
    {
        $html = $this->render($this->card(daysOfData: null, passRate: null));

        self::assertStringContainsString('We haven’t measured your readiness yet', $html);
        self::assertStringNotContainsString('0.0%', $html, 'A rate we never measured must never be printed as 0.0%.');
        self::assertStringNotContainsString('over 0 days', $html);
        self::assertStringNotContainsString(
            'You’re at full enforcement',
            $html,
            'Falling through to the terminal-stage reassurance would claim enforcement this domain does not have.',
        );
    }

    #[Test]
    public function aThinDataDomainIsToldHowMuchHistoryWeHaveSoFar(): void
    {
        $html = $this->render($this->card(
            daysOfData: 4,
            passRate: 100.0,
            blockingReasons: ['thin_data'],
            recommendedNextPolicy: DmarcPolicy::Quarantine,
        ));

        self::assertStringContainsString('still gathering data', $html);
        self::assertStringContainsString('4 days of reports', $html);
    }

    #[Test]
    public function aMeasuredButNotYetSafeDomainStillGetsItsRealAlignmentFigure(): void
    {
        $html = $this->render($this->card(
            daysOfData: 45,
            passRate: 82.5,
            recommendedNextPolicy: DmarcPolicy::Quarantine,
        ));

        self::assertStringContainsString('Alignment is 82.5% over 45 days', $html);
    }

    /**
     * The readiness macro is rendered directly rather than through the whole
     * component: the rest of the card is CSRF-protected forms that need a live
     * session, and none of that is what this behaviour is about. Importing the
     * real template (not a copy of the copy) keeps the assertion honest.
     */
    private function render(ManagedDmarcCardResult $card): string
    {
        $twig = self::getContainer()->get(Environment::class);
        assert($twig instanceof Environment);

        return $twig->createTemplate(
            '{% import "components/ManagedDmarcCard.html.twig" as managed %}{{ managed.readinessHint(card) }}',
        )->render(['card' => $card]);
    }

    /**
     * @param list<string> $blockingReasons
     */
    private function card(
        ?int $daysOfData,
        ?float $passRate,
        array $blockingReasons = [],
        ?DmarcPolicy $recommendedNextPolicy = null,
    ): ManagedDmarcCardResult {
        return new ManagedDmarcCardResult(
            state: ManagedDmarcCardState::Active,
            available: true,
            cnameTarget: 'acme.example._dmarc.sendvery.test',
            conflictingDmarcTxt: null,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            autoRampEnabled: false,
            autoRampPaused: false,
            autoRampStage: AutoRampStage::Monitoring,
            scheduledStage: null,
            scheduledAdvanceAt: null,
            cnameVerifiedAt: new \DateTimeImmutable('2026-07-26 03:04:05'),
            ready: false,
            eligibleForNextTier: false,
            recommendedNextPolicy: $recommendedNextPolicy,
            daysOfData: $daysOfData,
            passRate: $passRate,
            distinctSources: null,
            blockingReasons: $blockingReasons,
        );
    }
}

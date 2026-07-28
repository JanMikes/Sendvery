<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainReadinessSignals;
use App\Results\ManagedDmarcCardResult;
use App\Services\Dns\DmarcRampReadinessEvaluator;
use App\Tests\WebTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcCardState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
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
    public function aDomainAtFullEnforcementStaysAtFullEnforcementThroughAQuietPeriod(): void
    {
        // Reaching p=reject is terminal: the evaluator returns early with no
        // recommended next policy, and there is nothing left to measure
        // readiness FOR. A domain that finished the ramp and then had a quiet
        // couple of months has a null pass rate over the 60-day window — but
        // telling its owner "your first DMARC reports haven't arrived yet" is
        // false about a customer who completed the whole journey, and it is
        // exactly the mirror of the falsehood the docblock above this macro was
        // written to prevent. Absence of measurement does not un-enforce a
        // published policy.
        $html = $this->render($this->card(daysOfData: null, passRate: null, recommendedNextPolicy: null, blockingReasons: ['already_at_full_enforcement']));

        self::assertStringContainsString('You’re at full enforcement (reject)', $html);
        self::assertStringNotContainsString('We haven’t measured your readiness yet', $html);
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

    #[Test]
    public function aRealDomainWithNoMailInTheWindowReachesTheCardAsUnmeasured(): void
    {
        // The arm above existed but nothing could reach it: the readiness query
        // coalesced an empty 60-day window to 0.0, so a live managed domain that
        // simply has no recent mail always arrived carrying a measurement it
        // never had, and the card printed "Alignment is 0.0% over 70 days".
        // This walks the real read path — query, evaluator, card DTO, template.
        $card = $this->cardFromTheRealReadPath(DmarcPolicy::Quarantine);

        self::assertNull($card->passRate);

        $html = $this->render($card);
        self::assertStringContainsString('We haven’t measured your readiness yet', $html);
        self::assertStringNotContainsString('0.0%', $html);
    }

    #[Test]
    public function aRealDomainAlreadyAtRejectReadsAsFullyEnforcedDespiteTheQuietWindow(): void
    {
        // Same read path, one rung further along. This is the shape a paying
        // customer who finished the ramp and then had a quiet couple of months
        // actually produces: the evaluator returns the terminal verdict AND a
        // null pass rate together, and the card must lead with the policy that
        // is published rather than the mail that is missing.
        $card = $this->cardFromTheRealReadPath(DmarcPolicy::Reject);

        self::assertNull($card->passRate, 'The empty window really does produce no rate — the terminal arm is not dodging the null.');
        self::assertContains('already_at_full_enforcement', $card->blockingReasons);

        $html = $this->render($card);
        self::assertStringContainsString('You’re at full enforcement (reject)', $html);
        self::assertStringNotContainsString('We haven’t measured your readiness yet', $html);
    }

    /**
     * A managed domain with a long history and nothing at all in the trailing
     * 60-day window, run through query -> evaluator -> card DTO.
     */
    private function cardFromTheRealReadPath(DmarcPolicy $publishedPolicy): ManagedDmarcCardResult
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $team = new Team(id: Uuid::uuid7(), name: 'Unmeasured', slug: 'unmeasured-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $em->persist($team);
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'unmeasured-'.bin2hex(random_bytes(3)).'.example',
            createdAt: new \DateTimeImmutable('-90 days'),
            firstReportAt: new \DateTimeImmutable('-70 days'),
        );
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = $publishedPolicy;
        $domain->cnameVerifiedAt = new \DateTimeImmutable('-2 hours');
        $domain->lastPolicyChangeAt = new \DateTimeImmutable('-30 days');
        $em->persist($domain);
        $em->flush();

        $signals = self::getContainer()->get(GetDomainReadinessSignals::class);
        assert($signals instanceof GetDomainReadinessSignals);
        $evaluator = self::getContainer()->get(DmarcRampReadinessEvaluator::class);
        assert($evaluator instanceof DmarcRampReadinessEvaluator);

        return ManagedDmarcCardResult::build(
            $domain,
            $evaluator->evaluate($domain, $signals->forDomain($domain->id, [$team->id])),
            available: true,
            cnameTarget: 'irrelevant',
        );
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

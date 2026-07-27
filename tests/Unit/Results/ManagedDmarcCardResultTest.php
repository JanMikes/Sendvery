<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Results\ManagedDmarcCardResult;
use App\Results\RampReadinessResult;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcCardState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The managed-DMARC card must never invent a measurement it does not have.
 *
 * THE DEFECT THIS EXISTS FOR: `daysOfData` and `passRate` defaulted to `0` /
 * `0.0` when there was no readiness verdict to read. A zero is indistinguishable
 * from a real measurement, so the card could tell a customer their alignment was
 * "0.0% over 0 days" — the wording of total authentication failure — about a
 * domain nobody had measured yet. It also defeated the honest "still gathering
 * data" branch, because every downstream check saw a number and believed it.
 */
final class ManagedDmarcCardResultTest extends TestCase
{
    #[Test]
    public function measurementsStayNullWhenThereIsNoReadinessVerdictToRead(): void
    {
        $card = ManagedDmarcCardResult::build(
            $this->domain(),
            readiness: null,
            available: true,
            cnameTarget: 'acme.example._dmarc.sendvery.test',
        );

        self::assertNull($card->daysOfData, 'Zero days of data is a measurement; "we have not measured" is not.');
        self::assertNull($card->passRate, '0.0% means every message failed — it must never stand in for "no data".');
        self::assertNull($card->distinctSources);
        self::assertSame([], $card->blockingReasons);
        self::assertFalse($card->ready);
        self::assertFalse($card->eligibleForNextTier);
    }

    #[Test]
    public function measurementsArePassedThroughVerbatimWhenAVerdictExists(): void
    {
        $card = ManagedDmarcCardResult::build(
            $this->domain(),
            readiness: new RampReadinessResult(
                currentStage: AutoRampStage::Monitoring,
                recommendedNextPolicy: null,
                ready: false,
                eligibleForNextTier: false,
                regressionDetected: false,
                cnameVerified: true,
                daysOfData: 12,
                passRate: 0.0,
                distinctSources: 2,
                blockingReasons: ['thin_data'],
            ),
            available: true,
            cnameTarget: 'acme.example._dmarc.sendvery.test',
        );

        // A genuinely measured 0.0% is real information and must survive — the
        // nullability exists to separate "measured zero" from "not measured".
        self::assertSame(0.0, $card->passRate);
        self::assertSame(12, $card->daysOfData);
        self::assertSame(2, $card->distinctSources);
        self::assertSame(['thin_data'], $card->blockingReasons);
    }

    #[Test]
    public function anActiveCardCarriesTheTimestampBehindItsVerificationClaim(): void
    {
        // The card asserts the CNAME is verified; the only evidence it has is a
        // timestamp refreshed by the nightly DNS sweep. Carrying it is what lets
        // the card say WHEN, instead of implying "right now".
        $confirmedAt = new \DateTimeImmutable('2026-07-26 03:04:05');
        $domain = $this->domain();
        $domain->cnameVerifiedAt = $confirmedAt;

        $card = ManagedDmarcCardResult::build($domain, null, true, 'acme.example._dmarc.sendvery.test');

        self::assertSame(ManagedDmarcCardState::Active, $card->state);
        self::assertSame($confirmedAt, $card->cnameVerifiedAt);
    }

    private function domain(): MonitoredDomain
    {
        $now = new \DateTimeImmutable('2026-07-27 12:00:00');
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Card',
            slug: 'card-'.Uuid::uuid7()->toString(),
            createdAt: $now,
        );

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'acme.example',
            createdAt: $now,
        );
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::None;
        $domain->cloudflareHostedDmarcRecordId = 'cf-1';

        return $domain;
    }
}

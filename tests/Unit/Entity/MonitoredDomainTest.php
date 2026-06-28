<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\AutoRampAdvanceScheduled;
use App\Events\AutoRampDisabled;
use App\Events\AutoRampEnabled;
use App\Events\AutoRampPaused;
use App\Events\CnameVerified;
use App\Events\DmarcPolicyChanged;
use App\Events\DomainAdded;
use App\Events\ManagedDmarcDisabled;
use App\Events\ManagedDmarcEnabled;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\CnameVerificationOutcome;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MonitoredDomainTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team',
            slug: 'test-team',
            createdAt: new \DateTimeImmutable(),
        );
        $createdAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'example.com',
            createdAt: $createdAt,
        );

        self::assertSame($id, $domain->id);
        self::assertSame($team, $domain->team);
        self::assertSame('example.com', $domain->domain);
        self::assertSame($createdAt, $domain->createdAt);
        self::assertNull($domain->dmarcPolicy);
        self::assertNull($domain->spfVerifiedAt);
        self::assertNull($domain->dkimVerifiedAt);
        self::assertNull($domain->dmarcVerifiedAt);
        self::assertNull($domain->firstReportAt);
    }

    public function testConstructorWithOptionalFields(): void
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );

        $verifiedAt = new \DateTimeImmutable('2026-04-01 09:00:00');
        $reportAt = new \DateTimeImmutable('2026-04-02 06:00:00');

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'test.com',
            createdAt: new \DateTimeImmutable(),
            dmarcPolicy: DmarcPolicy::Reject,
            spfVerifiedAt: $verifiedAt,
            dkimVerifiedAt: $verifiedAt,
            dmarcVerifiedAt: $verifiedAt,
            firstReportAt: $reportAt,
        );

        self::assertSame(DmarcPolicy::Reject, $domain->dmarcPolicy);
        self::assertSame($verifiedAt, $domain->spfVerifiedAt);
        self::assertSame($verifiedAt, $domain->dkimVerifiedAt);
        self::assertSame($verifiedAt, $domain->dmarcVerifiedAt);
        self::assertSame($reportAt, $domain->firstReportAt);
    }

    public function testRecordsDomainAddedEvent(): void
    {
        $id = Uuid::uuid7();
        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );
        // Clear team events
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );

        $events = $domain->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(DomainAdded::class, $events[0]);
        self::assertSame($id, $events[0]->domainId);
        self::assertSame($teamId, $events[0]->teamId);
    }

    #[Test]
    public function enableManagedDmarcSeedsThePolicyAndStageFromTheSeed(): void
    {
        $domain = $this->domain();

        $domain->enableManagedDmarc(new ManagedDmarcPolicy(DmarcPolicy::Quarantine), $this->at('2026-06-10 09:00:00'));

        self::assertSame(DmarcSetupMode::ManagedCname, $domain->dmarcSetupMode);
        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP);
        self::assertSame(AutoRampStage::Quarantine, $domain->autoRampStage);
        self::assertEquals($this->at('2026-06-10 09:00:00'), $domain->managedDmarcEnabledAt);
        self::assertEventTypes([ManagedDmarcEnabled::class], $domain->popEvents());
    }

    #[Test]
    public function enableManagedDmarcIsANoOpEventWiseWhenAlreadyManaged(): void
    {
        $domain = $this->domain();
        $domain->enableManagedDmarc(ManagedDmarcPolicy::monitoring(), $this->at('2026-06-10 09:00:00'));
        $domain->popEvents();

        $domain->enableManagedDmarc(new ManagedDmarcPolicy(DmarcPolicy::Reject), $this->at('2026-06-11 09:00:00'));

        // Neither reseeds the policy nor re-emits.
        self::assertSame(DmarcPolicy::None, $domain->managedPolicyP);
        self::assertSame([], $domain->popEvents());
    }

    #[Test]
    public function changeManagedPolicyRecordsDmarcPolicyChangedOnlyWhenContentActuallyChanges(): void
    {
        $domain = $this->managed(DmarcPolicy::None);

        $domain->changeManagedPolicy(new ManagedDmarcPolicy(DmarcPolicy::Quarantine), PolicyChangeSource::Manual, null, $this->at('2026-06-12 09:00:00'));
        self::assertEventTypes([DmarcPolicyChanged::class], $domain->popEvents());
        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP);

        // Re-applying the same policy is a no-op event-wise.
        $domain->changeManagedPolicy(new ManagedDmarcPolicy(DmarcPolicy::Quarantine), PolicyChangeSource::Manual, null, $this->at('2026-06-13 09:00:00'));
        self::assertSame([], $domain->popEvents());
    }

    #[Test]
    public function changeManagedPolicyIsAGuardedNoOpOnASelfTxtDomain(): void
    {
        // A stray manual SetDmarcPolicy on a self-TXT domain must never publish a
        // hosted record for a domain that never delegated DMARC to Sendvery.
        $domain = $this->domain();

        $domain->changeManagedPolicy(new ManagedDmarcPolicy(DmarcPolicy::Reject), PolicyChangeSource::Manual, null, $this->at('2026-06-12 09:00:00'));

        self::assertSame(DmarcSetupMode::SelfTxt, $domain->dmarcSetupMode);
        self::assertNull($domain->managedPolicyP);
        self::assertSame([], $domain->popEvents());
    }

    #[Test]
    public function changeManagedPolicyCancelsAnyPendingAutoRampSchedule(): void
    {
        // A published change supersedes a stale scheduled advance, so it can't
        // later trip a spurious pause when it comes due.
        $domain = $this->managed(DmarcPolicy::None);
        $domain->scheduleAutoRampAdvance(AutoRampStage::Quarantine, $this->at('2026-06-20 09:00:00'));
        $domain->popEvents();
        self::assertNotNull($domain->autoRampScheduledAdvanceAt);

        $domain->changeManagedPolicy(new ManagedDmarcPolicy(DmarcPolicy::Quarantine), PolicyChangeSource::Manual, null, $this->at('2026-06-15 09:00:00'));

        self::assertNull($domain->autoRampScheduledStage);
        self::assertNull($domain->autoRampScheduledAdvanceAt);
    }

    #[Test]
    public function changeManagedPolicyPreservesSubdomainPolicyAndCoverage(): void
    {
        $domain = $this->managed(DmarcPolicy::None);

        $domain->changeManagedPolicy(new ManagedDmarcPolicy(DmarcPolicy::Quarantine, DmarcPolicy::None, 25), PolicyChangeSource::Manual, null, $this->at('2026-06-15 09:00:00'));

        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP);
        self::assertSame(DmarcPolicy::None, $domain->managedPolicySp);
        self::assertSame(25, $domain->managedPolicyPct);
    }

    #[Test]
    public function reEnablingManagedDmarcClearsAStaleTeardownMarker(): void
    {
        $domain = $this->managed(DmarcPolicy::Quarantine);
        $domain->disableManagedDmarc($this->at('2026-06-20 09:00:00'));
        self::assertNotNull($domain->hostedDmarcTeardownAt);
        $domain->popEvents();

        $domain->enableManagedDmarc(new ManagedDmarcPolicy(DmarcPolicy::None), $this->at('2026-06-21 09:00:00'));

        self::assertNull($domain->hostedDmarcTeardownAt);
    }

    #[Test]
    public function rollbackResetsAutoRampStageToMatchThePublishedPolicy(): void
    {
        $domain = $this->managed(DmarcPolicy::Reject);
        self::assertSame(AutoRampStage::Reject, $domain->autoRampStage);

        $domain->changeManagedPolicy(new ManagedDmarcPolicy(DmarcPolicy::Quarantine), PolicyChangeSource::Rollback, null, $this->at('2026-06-14 09:00:00'));

        self::assertSame(AutoRampStage::Quarantine, $domain->autoRampStage);
        $events = $domain->popEvents();
        self::assertEventTypes([DmarcPolicyChanged::class], $events);
        self::assertInstanceOf(DmarcPolicyChanged::class, $events[0]);
        self::assertSame(PolicyChangeSource::Rollback, $events[0]->source);
    }

    #[Test]
    public function markCnameVerifiedEmitsOnceOnTheNullToSetTransitionAndClearsOnFailure(): void
    {
        $domain = $this->managed(DmarcPolicy::None);

        $domain->markCnameVerified(CnameVerificationOutcome::Verified, $this->at('2026-06-12 09:00:00'));
        self::assertEventTypes([CnameVerified::class], $domain->popEvents());
        self::assertNotNull($domain->cnameVerifiedAt);

        // Re-verifying does not re-emit.
        $domain->markCnameVerified(CnameVerificationOutcome::Verified, $this->at('2026-06-13 09:00:00'));
        self::assertSame([], $domain->popEvents());

        // A non-verified outcome clears verification (freezes the ramp via gates).
        $domain->markCnameVerified(CnameVerificationOutcome::Missing, $this->at('2026-06-14 09:00:00'));
        self::assertNull($domain->cnameVerifiedAt);
    }

    #[Test]
    public function autoRampEnableDisablePauseResumeBehaveAndEmit(): void
    {
        $domain = $this->managed(DmarcPolicy::None);

        $domain->enableAutoRamp($this->at('2026-06-12 09:00:00'));
        self::assertTrue($domain->autoRampEnabled);
        self::assertEventTypes([AutoRampEnabled::class], $domain->popEvents());

        // Already enabled and not paused — no-op.
        $domain->enableAutoRamp($this->at('2026-06-12 10:00:00'));
        self::assertSame([], $domain->popEvents());

        $domain->scheduleAutoRampAdvance(AutoRampStage::Quarantine, $this->at('2026-06-15 09:00:00'));
        self::assertEventTypes([AutoRampAdvanceScheduled::class], $domain->popEvents());
        // Scheduling the same advance again is a no-op.
        $domain->scheduleAutoRampAdvance(AutoRampStage::Quarantine, $this->at('2026-06-15 09:00:00'));
        self::assertSame([], $domain->popEvents());

        $domain->pauseAutoRamp('Alignment dropped', $this->at('2026-06-13 09:00:00'));
        self::assertNotNull($domain->autoRampPausedAt);
        self::assertNull($domain->autoRampScheduledStage);
        $paused = $domain->popEvents();
        self::assertEventTypes([AutoRampPaused::class], $paused);
        self::assertInstanceOf(AutoRampPaused::class, $paused[0]);
        self::assertSame('Alignment dropped', $paused[0]->reason);
        // Pausing again is a no-op.
        $domain->pauseAutoRamp('again', $this->at('2026-06-13 10:00:00'));
        self::assertSame([], $domain->popEvents());

        // Resuming clears the pause without re-emitting AutoRampEnabled.
        $domain->resumeAutoRamp();
        self::assertNull($domain->autoRampPausedAt);
        self::assertSame([], $domain->popEvents());

        $domain->clearAutoRampSchedule();
        self::assertNull($domain->autoRampScheduledAdvanceAt);

        $domain->disableAutoRamp();
        self::assertFalse($domain->autoRampEnabled);
        self::assertEventTypes([AutoRampDisabled::class], $domain->popEvents());
        // Already disabled — no-op.
        $domain->disableAutoRamp();
        self::assertSame([], $domain->popEvents());
    }

    #[Test]
    public function enableAutoRampWhilePausedResumesWithoutReEmitting(): void
    {
        $domain = $this->managed(DmarcPolicy::None);
        $domain->enableAutoRamp($this->at('2026-06-12 09:00:00'));
        $domain->pauseAutoRamp('safety', $this->at('2026-06-13 09:00:00'));
        $domain->popEvents();

        $domain->enableAutoRamp($this->at('2026-06-14 09:00:00'));

        self::assertTrue($domain->autoRampEnabled);
        self::assertNull($domain->autoRampPausedAt);
        self::assertSame([], $domain->popEvents());
    }

    #[Test]
    public function disableManagedDmarcKeepsTheHostedRecordIdForSafeTeardown(): void
    {
        $domain = $this->managed(DmarcPolicy::Quarantine);
        $domain->cloudflareHostedDmarcRecordId = 'cf-hosted-123';
        $domain->popEvents();

        $domain->disableManagedDmarc($this->at('2026-06-20 09:00:00'));

        self::assertSame(DmarcSetupMode::SelfTxt, $domain->dmarcSetupMode);
        self::assertFalse($domain->autoRampEnabled);
        self::assertNull($domain->managedPolicyP);
        self::assertSame('cf-hosted-123', $domain->cloudflareHostedDmarcRecordId);
        self::assertNotNull($domain->hostedDmarcTeardownAt);

        $events = $domain->popEvents();
        self::assertEventTypes([ManagedDmarcDisabled::class], $events);
        self::assertInstanceOf(ManagedDmarcDisabled::class, $events[0]);
        self::assertSame('cf-hosted-123', $events[0]->hostedRecordId);

        // Already self-TXT — no-op.
        $domain->disableManagedDmarc($this->at('2026-06-21 09:00:00'));
        self::assertSame([], $domain->popEvents());
    }

    #[Test]
    public function currentManagedPolicyIsNullUntilSeeded(): void
    {
        $domain = $this->domain();
        self::assertNull($domain->currentManagedPolicy());

        $domain->enableManagedDmarc(new ManagedDmarcPolicy(DmarcPolicy::Quarantine, DmarcPolicy::Reject, 50), $this->at('2026-06-12 09:00:00'));
        $policy = $domain->currentManagedPolicy();

        self::assertNotNull($policy);
        self::assertTrue($policy->equals(new ManagedDmarcPolicy(DmarcPolicy::Quarantine, DmarcPolicy::Reject, 50)));
    }

    private function domain(): MonitoredDomain
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'acme.example',
            createdAt: new \DateTimeImmutable('2026-06-01 09:00:00'),
        );
        $domain->popEvents();

        return $domain;
    }

    private function managed(DmarcPolicy $p): MonitoredDomain
    {
        $domain = $this->domain();
        $domain->enableManagedDmarc(new ManagedDmarcPolicy($p), $this->at('2026-06-05 09:00:00'));
        $domain->popEvents();

        return $domain;
    }

    private function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    /**
     * @param list<class-string> $expected
     * @param array<object>      $events
     */
    private static function assertEventTypes(array $expected, array $events): void
    {
        self::assertSame($expected, array_values(array_map(static fn (object $e): string => $e::class, $events)));
    }
}

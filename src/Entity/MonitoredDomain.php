<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\AutoRampAdvanceScheduled;
use App\Events\AutoRampDisabled;
use App\Events\AutoRampEnabled;
use App\Events\AutoRampPaused;
use App\Events\CnameVerified;
use App\Events\DmarcPolicyChanged;
use App\Events\DomainAdded;
use App\Events\DomainDmarcVerified;
use App\Events\ManagedDmarcDisabled;
use App\Events\ManagedDmarcEnabled;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\CnameVerificationOutcome;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Domain ownership is system-wide unique: at most one team can monitor a
 * given domain at any time, enforced by a case-insensitive functional unique
 * index in the database (see migration Version20260523100000). The Add-time
 * check in AddDomainController catches the conflict early and redirects the
 * user to the "domain taken" page; the index is the race-condition backstop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'monitored_domain')]
final class MonitoredDomain implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false)]
    public readonly Team $team;

    #[ORM\Column(length: 255)]
    public string $domain;

    #[ORM\Column(type: 'string', nullable: true, enumType: DmarcPolicy::class)]
    public ?DmarcPolicy $dmarcPolicy;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $spfVerifiedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $dkimVerifiedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $dmarcVerifiedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $firstReportAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    /**
     * TASK-146 — per-domain DKIM selector preference. When NULL, the DkimChecker
     * brute-forces selectors from DkimSelectorRegistry::PROVIDER_SELECTORS;
     * when set, the checker queries this selector directly. Teams whose
     * selector isn't in the canonical registry (custom selectors from
     * internal rotation, niche providers, etc.) set this so the dashboard
     * stops reporting "DKIM not found" forever.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $dkimSelector;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $cloudflareAuthRecordId = null;

    /**
     * DEC-058 — Managed DMARC (CNAME) fields. All property-initialized (NOT
     * constructor args) so existing domains construct unchanged, exactly like
     * cloudflareAuthRecordId. DB defaults are declared in the mapping so
     * doctrine:schema:validate stays green against the migration.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: DmarcSetupMode::class, options: ['default' => 'self_txt'])]
    public DmarcSetupMode $dmarcSetupMode = DmarcSetupMode::SelfTxt;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $cloudflareHostedDmarcRecordId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: DmarcPolicy::class)]
    public ?DmarcPolicy $managedPolicyP = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: DmarcPolicy::class)]
    public ?DmarcPolicy $managedPolicySp = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $managedPolicyPct = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $autoRampEnabled = false;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: AutoRampStage::class)]
    public ?AutoRampStage $autoRampStage = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: AutoRampStage::class)]
    public ?AutoRampStage $autoRampScheduledStage = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $autoRampScheduledAdvanceAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $autoRampPausedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $managedDmarcEnabledAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $cnameVerifiedAt = null;

    /** Dwell anchor — the readiness evaluator requires N days since this. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastPolicyChangeAt = null;

    /** Offboard marker — set on disable so the sync cron can tear down safely. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $hostedDmarcTeardownAt = null;

    public function __construct(
        UuidInterface $id,
        Team $team,
        string $domain,
        \DateTimeImmutable $createdAt,
        ?DmarcPolicy $dmarcPolicy = null,
        ?\DateTimeImmutable $spfVerifiedAt = null,
        ?\DateTimeImmutable $dkimVerifiedAt = null,
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?\DateTimeImmutable $firstReportAt = null,
        ?string $dkimSelector = null,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->domain = $domain;
        $this->createdAt = $createdAt;
        $this->dmarcPolicy = $dmarcPolicy;
        $this->spfVerifiedAt = $spfVerifiedAt;
        $this->dkimVerifiedAt = $dkimVerifiedAt;
        $this->dmarcVerifiedAt = $dmarcVerifiedAt;
        $this->firstReportAt = $firstReportAt;
        $this->dkimSelector = $dkimSelector;

        $this->recordThat(new DomainAdded($this->id, $this->team->id));
    }

    /**
     * Records the first successful DMARC DNS verification and emits a
     * DomainDmarcVerified event so listeners (notably the quarantine
     * releaser) can react. Re-verifications are a no-op event-wise so we
     * don't fire duplicate releases on every nightly DNS sweep.
     */
    public function markDmarcVerified(\DateTimeImmutable $verifiedAt): void
    {
        $wasUnverified = null === $this->dmarcVerifiedAt;
        $this->dmarcVerifiedAt = $verifiedAt;

        if ($wasUnverified) {
            $this->recordThat(new DomainDmarcVerified(
                domainId: $this->id,
                teamId: $this->team->id,
                domainName: $this->domain,
            ));
        }
    }

    /**
     * The currently published managed policy, or null when none is set yet
     * (self-TXT, or managed-but-not-yet-seeded). Built from the persisted
     * p/sp/pct columns — the published policy is the single source of truth for
     * the current ramp stage.
     */
    public function currentManagedPolicy(): ?ManagedDmarcPolicy
    {
        if (null === $this->managedPolicyP) {
            return null;
        }

        return new ManagedDmarcPolicy($this->managedPolicyP, $this->managedPolicySp, $this->managedPolicyPct ?? 100);
    }

    /**
     * Switch the domain to managed-CNAME and seed the first hosted policy
     * (enforcement-preserving — the seed comes from the live record). Idempotent:
     * re-enabling an already-managed domain neither reseeds nor re-emits, so a
     * dropped event can be retried without clobbering a ramped policy.
     */
    public function enableManagedDmarc(ManagedDmarcPolicy $seed, \DateTimeImmutable $now): void
    {
        if (DmarcSetupMode::ManagedCname === $this->dmarcSetupMode) {
            return;
        }

        $this->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $this->managedPolicyP = $seed->p;
        $this->managedPolicySp = $seed->sp;
        $this->managedPolicyPct = $seed->pct;
        $this->autoRampStage = AutoRampStage::fromPolicy($seed->p);
        $this->managedDmarcEnabledAt = $now;
        $this->lastPolicyChangeAt = $now;
        // Re-enabling after a prior teardown clears the stale offboard marker so
        // the hosted record is never read as "pending teardown" again.
        $this->hostedDmarcTeardownAt = null;

        $this->recordThat(new ManagedDmarcEnabled($this->id, $this->team->id, $this->domain));
    }

    /**
     * The single funnel for every managed policy change (manual set, guided
     * advance, auto-ramp, rollback, downgrade-freeze). Records DmarcPolicyChanged
     * — and so republishes + writes one audit row — ONLY when the effective
     * content differs, keeping cron re-runs idempotent. autoRampStage is always
     * re-derived from the new policy so rollback resets it to match.
     */
    public function changeManagedPolicy(ManagedDmarcPolicy $policy, PolicyChangeSource $source, ?UuidInterface $actorUserId, \DateTimeImmutable $now): void
    {
        // Only a managed-CNAME domain has a hosted policy to change. Guard the
        // funnel so a stray manual SetDmarcPolicy on a self-TXT domain can't
        // publish a hosted record for a domain that never delegated DMARC to us.
        if (DmarcSetupMode::ManagedCname !== $this->dmarcSetupMode) {
            return;
        }

        $current = $this->currentManagedPolicy();

        if (null !== $current && $current->equals($policy)) {
            return;
        }

        $this->managedPolicyP = $policy->p;
        $this->managedPolicySp = $policy->sp;
        $this->managedPolicyPct = $policy->pct;
        $this->autoRampStage = AutoRampStage::fromPolicy($policy->p);
        $this->lastPolicyChangeAt = $now;

        // A published change supersedes any pending auto-ramp schedule — the cron
        // re-evaluates and re-schedules the next rung from the new tier. Without
        // this, a manual set mid-schedule would later trip a spurious pause when
        // the now-stale scheduled advance came due.
        $this->clearAutoRampSchedule();

        $this->recordThat(new DmarcPolicyChanged($this->id, $this->team->id, $this->domain, $current, $policy, $source, $actorUserId));
    }

    /**
     * Records the managed CNAME verification result. On Verified it sets
     * cnameVerifiedAt (emitting CnameVerified on the null->set transition only);
     * any non-verified outcome clears it, which freezes the ramp via the
     * readiness gates until the CNAME is restored.
     */
    public function markCnameVerified(CnameVerificationOutcome $outcome, \DateTimeImmutable $now): void
    {
        if (CnameVerificationOutcome::Verified !== $outcome) {
            $this->cnameVerifiedAt = null;

            return;
        }

        $wasUnverified = null === $this->cnameVerifiedAt;
        $this->cnameVerifiedAt = $now;

        if ($wasUnverified) {
            $this->recordThat(new CnameVerified($this->id, $this->team->id, $this->domain));
        }
    }

    public function enableAutoRamp(\DateTimeImmutable $now): void
    {
        if ($this->autoRampEnabled && null === $this->autoRampPausedAt) {
            return;
        }

        $wasEnabled = $this->autoRampEnabled;
        $this->autoRampEnabled = true;
        $this->autoRampPausedAt = null;

        if (!$wasEnabled) {
            $this->recordThat(new AutoRampEnabled($this->id, $this->team->id, $this->domain));
        }
    }

    public function disableAutoRamp(): void
    {
        if (!$this->autoRampEnabled) {
            return;
        }

        $this->autoRampEnabled = false;
        $this->autoRampScheduledStage = null;
        $this->autoRampScheduledAdvanceAt = null;

        $this->recordThat(new AutoRampDisabled($this->id, $this->team->id, $this->domain));
    }

    public function scheduleAutoRampAdvance(AutoRampStage $to, \DateTimeImmutable $at): void
    {
        if ($this->autoRampScheduledStage === $to && $this->autoRampScheduledAdvanceAt == $at) {
            return;
        }

        $this->autoRampScheduledStage = $to;
        $this->autoRampScheduledAdvanceAt = $at;

        $this->recordThat(new AutoRampAdvanceScheduled($this->id, $this->team->id, $this->domain, $to, $at));
    }

    public function clearAutoRampSchedule(): void
    {
        $this->autoRampScheduledStage = null;
        $this->autoRampScheduledAdvanceAt = null;
    }

    /**
     * Pause the ramp: clear any pending schedule and stamp autoRampPausedAt.
     * The current policy stays live — pausing never loosens. Idempotent so a
     * repeated safety trip doesn't re-email.
     */
    public function pauseAutoRamp(string $reason, \DateTimeImmutable $now): void
    {
        if (null !== $this->autoRampPausedAt) {
            return;
        }

        $this->autoRampPausedAt = $now;
        $this->autoRampScheduledStage = null;
        $this->autoRampScheduledAdvanceAt = null;

        $this->recordThat(new AutoRampPaused($this->id, $this->team->id, $this->domain, $reason));
    }

    public function resumeAutoRamp(): void
    {
        $this->autoRampPausedAt = null;
    }

    /**
     * Switch back to self-TXT. Turns off auto-ramp and clears the policy intent,
     * but KEEPS cloudflareHostedDmarcRecordId and stamps hostedDmarcTeardownAt so
     * the sync cron can delete the hosted record only once the CNAME no longer
     * points at us (dangling-safe — never break a customer's live DMARC).
     */
    public function disableManagedDmarc(\DateTimeImmutable $now): void
    {
        if (DmarcSetupMode::SelfTxt === $this->dmarcSetupMode) {
            return;
        }

        $hostedRecordId = $this->cloudflareHostedDmarcRecordId;

        $this->dmarcSetupMode = DmarcSetupMode::SelfTxt;
        $this->autoRampEnabled = false;
        $this->autoRampPausedAt = null;
        $this->autoRampStage = null;
        $this->autoRampScheduledStage = null;
        $this->autoRampScheduledAdvanceAt = null;
        $this->managedPolicyP = null;
        $this->managedPolicySp = null;
        $this->managedPolicyPct = null;
        $this->cnameVerifiedAt = null;
        $this->hostedDmarcTeardownAt = $now;

        $this->recordThat(new ManagedDmarcDisabled($this->id, $this->team->id, $this->domain, $hostedRecordId));
    }
}

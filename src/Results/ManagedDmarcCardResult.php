<?php

declare(strict_types=1);

namespace App\Results;

use App\Entity\MonitoredDomain;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcCardState;

/**
 * Everything the dashboard ManagedDmarcCard needs, assembled in
 * ShowDomainDetailController from the loaded MonitoredDomain + its readiness
 * verdict + the team's entitlement (DEC-058 §3.4). Pure view data — the card
 * template branches on `state`.
 */
final readonly class ManagedDmarcCardResult
{
    /** @param list<string> $blockingReasons */
    public function __construct(
        public ManagedDmarcCardState $state,
        public bool $available,
        public string $cnameTarget,
        public ?DmarcPolicy $policyP,
        public ?DmarcPolicy $policySp,
        public ?int $policyPct,
        public bool $autoRampEnabled,
        public bool $autoRampPaused,
        public ?AutoRampStage $autoRampStage,
        public ?AutoRampStage $scheduledStage,
        public ?\DateTimeImmutable $scheduledAdvanceAt,
        public ?\DateTimeImmutable $cnameVerifiedAt,
        public bool $ready,
        public bool $eligibleForNextTier,
        public ?DmarcPolicy $recommendedNextPolicy,
        public int $daysOfData,
        public float $passRate,
        public int $distinctSources,
        public array $blockingReasons,
    ) {
    }

    public static function build(
        MonitoredDomain $domain,
        ?RampReadinessResult $readiness,
        bool $available,
        string $cnameTarget,
    ): self {
        $managed = DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode;
        $hostedRecordPresent = null !== $domain->cloudflareHostedDmarcRecordId;
        $verified = null !== $domain->cnameVerifiedAt;
        $paused = null !== $domain->autoRampPausedAt;

        $state = match (true) {
            !$managed => ManagedDmarcCardState::NotEnabled,
            !$available => ManagedDmarcCardState::Frozen,
            !$hostedRecordPresent => ManagedDmarcCardState::Preparing,
            $verified => ManagedDmarcCardState::Active,
            // Unverified + paused means the CNAME was lost / a rail tripped, not a
            // fresh enable still propagating.
            $paused => ManagedDmarcCardState::Error,
            default => ManagedDmarcCardState::CnamePending,
        };

        return new self(
            state: $state,
            available: $available,
            cnameTarget: $cnameTarget,
            policyP: $domain->managedPolicyP,
            policySp: $domain->managedPolicySp,
            policyPct: $domain->managedPolicyPct,
            autoRampEnabled: $domain->autoRampEnabled,
            autoRampPaused: $paused,
            autoRampStage: $domain->autoRampStage,
            scheduledStage: $domain->autoRampScheduledStage,
            scheduledAdvanceAt: $domain->autoRampScheduledAdvanceAt,
            cnameVerifiedAt: $domain->cnameVerifiedAt,
            ready: $readiness->ready ?? false,
            eligibleForNextTier: $readiness->eligibleForNextTier ?? false,
            recommendedNextPolicy: $readiness?->recommendedNextPolicy?->p,
            daysOfData: $readiness->daysOfData ?? 0,
            passRate: $readiness->passRate ?? 0.0,
            distinctSources: $readiness->distinctSources ?? 0,
            blockingReasons: $readiness->blockingReasons ?? [],
        );
    }
}

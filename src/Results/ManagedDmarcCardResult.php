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
    /**
     * @param list<string> $blockingReasons
     * @param string|null  $conflictingDmarcTxt the customer's own `_dmarc` TXT still blocking the CNAME (RFC 1034 forbids the two coexisting), null when there is none
     * @param int|null     $daysOfData          days of report history the readiness verdict was measured over, null when no verdict has been computed
     * @param float|null   $passRate            aligned pass rate the readiness verdict was measured over, null when no verdict has been computed — never 0.0 for "unmeasured"
     * @param int|null     $distinctSources     distinct sending sources seen, null when no verdict has been computed
     */
    public function __construct(
        public ManagedDmarcCardState $state,
        public bool $available,
        public string $cnameTarget,
        public ?string $conflictingDmarcTxt,
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
        public ?int $daysOfData,
        public ?float $passRate,
        public ?int $distinctSources,
        public array $blockingReasons,
    ) {
    }

    public static function build(
        MonitoredDomain $domain,
        ?RampReadinessResult $readiness,
        bool $available,
        string $cnameTarget,
        ?string $conflictingDmarcTxt = null,
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
            // Only meaningful while the CNAME is outstanding — a verified CNAME
            // cannot have a TXT beside it, so there is nothing to warn about.
            conflictingDmarcTxt: $verified ? null : $conflictingDmarcTxt,
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
            // Measurements stay NULL when there is no readiness verdict to read.
            // They used to default to 0/0.0, which the card then rendered as
            // "Alignment is 0.0% over 0 days" — indistinguishable from total
            // authentication failure — and which ALSO defeated the honest
            // `thin_data` branch below it, because a zero looks like a real
            // measurement to every downstream check. Absent is not zero.
            daysOfData: $readiness?->daysOfData,
            passRate: $readiness?->passRate,
            distinctSources: $readiness?->distinctSources,
            blockingReasons: $readiness->blockingReasons ?? [],
        );
    }
}

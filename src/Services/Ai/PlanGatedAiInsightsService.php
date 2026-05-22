<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiNotEnabledForPlan;
use App\Exceptions\AiQuotaExceeded;
use App\Repository\TeamRepository;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use Ramsey\Uuid\UuidInterface;

/**
 * Decorates any `AiInsightsService` with plan + quota gating. All five
 * operations refuse when the team's plan has no AI; `explainReport` also
 * enforces the monthly on-demand quota and increments the counter on
 * success.
 *
 * The four automatic features (digest, anomaly, remediation, sender label)
 * are gated by plan only — they fire on triggers (cron / event), not on
 * user clicks, so quota would be the wrong cost lever. `explainReport` is
 * the user-initiated button, hence the per-call quota.
 *
 * Wired in `config/services.php` so the `AiInsightsService` interface
 * resolves to `PlanGatedAiInsightsService` wrapping `StubAiInsightsService`.
 * When real AI lands, only the inner binding changes.
 */
final readonly class PlanGatedAiInsightsService implements AiInsightsService
{
    public function __construct(
        private AiInsightsService $inner,
        private TeamRepository $teams,
        private PlanEnforcement $enforcement,
        private PlanLimits $limits,
    ) {
    }

    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        $this->assertPlanHasAi($teamId);

        return $this->inner->generateWeeklyDigest($teamId);
    }

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
    {
        $this->assertPlanHasAi($teamId);

        return $this->inner->explainAnomaly($reportId, $teamId);
    }

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        $plan = $this->assertPlanHasAi($teamId);

        if (!$this->enforcement->canUseOnDemandAi($teamId->toString(), $plan)) {
            throw new AiQuotaExceeded(used: $this->enforcement->getOnDemandAiUsage($teamId->toString()), limit: $this->limits->getOnDemandAiQuota($plan));
        }

        $result = $this->inner->explainReport($reportId, $teamId);

        $this->enforcement->incrementOnDemandAiUsage($teamId->toString());

        return $result;
    }

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
    {
        // Remediation guidance fires on a DNS-check trigger; the caller
        // resolves the domain's team and is responsible for skipping when
        // the plan has no AI. Stub passthrough — no quota involved.
        return $this->inner->generateRemediationGuidance($domainId, $failure);
    }

    public function labelSender(string $ip, string $domain): SenderLabelResult
    {
        // Smart sender labeling runs against newly observed IPs across all
        // AI-enabled teams. The caller decides whether to enqueue work for
        // a given team. No quota — Haiku is cheap.
        return $this->inner->labelSender($ip, $domain);
    }

    private function assertPlanHasAi(UuidInterface $teamId): SubscriptionPlan
    {
        $plan = $this->teams->get($teamId)->getSubscriptionPlan();

        if (!$plan->hasAi()) {
            throw new AiNotEnabledForPlan($plan);
        }

        return $plan;
    }
}

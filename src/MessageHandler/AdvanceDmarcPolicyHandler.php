<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\AdvanceDmarcPolicy;
use App\Query\GetDomainReadinessSignals;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\DmarcRampReadinessEvaluator;
use App\Services\Stripe\PlanEnforcement;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdvanceDmarcPolicyHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private GetTeamPlan $getTeamPlan,
        private PlanEnforcement $planEnforcement,
        private GetDomainReadinessSignals $readinessSignals,
        private DmarcRampReadinessEvaluator $evaluator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AdvanceDmarcPolicy $message): void
    {
        $teamId = Uuid::fromString($message->teamId);
        $domain = $this->monitoredDomainRepository->findForTeams($message->domainId, [$teamId]);

        if (null === $domain) {
            throw new \RuntimeException('Domain not found or not owned by team.');
        }

        // Tightening always requires entitlement (defense-in-depth on top of the
        // cron's entitlement JOIN and the controller's plan gate).
        $plan = $this->getTeamPlan->forTeam($message->teamId);
        if (!$this->planEnforcement->canUseManagedDmarc($plan)) {
            throw new ManagedDmarcNotAvailable($plan);
        }

        // Re-evaluate readiness server-side — never trust the caller. No-op when
        // not eligible, so a stale button or a since-regressed domain can't tighten.
        $readiness = $this->evaluator->evaluate(
            $domain,
            $this->readinessSignals->forDomain($message->domainId, [$teamId]),
        );

        if (!$readiness->eligibleForNextTier || null === $readiness->recommendedNextPolicy) {
            return;
        }

        // changeManagedPolicy clears any pending auto-ramp schedule on a real
        // change, so the cron re-evaluates from scratch for the next rung.
        $domain->changeManagedPolicy(
            $readiness->recommendedNextPolicy,
            $message->source,
            $message->actorUserId,
            $this->clock->now(),
        );
    }
}

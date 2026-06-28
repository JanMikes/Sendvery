<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\SetDmarcPolicy;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Services\Stripe\PlanEnforcement;
use App\Value\Dns\ManagedDmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SetDmarcPolicyHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private GetTeamPlan $getTeamPlan,
        private PlanEnforcement $planEnforcement,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SetDmarcPolicy $message): void
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            $message->domainId,
            [Uuid::fromString($message->teamId)],
        );

        if (null === $domain) {
            throw new \RuntimeException('Domain not found or not owned by team.');
        }

        // A user's Manual change requires entitlement; system Rollback /
        // DowngradeFreeze (loosening / freezing) is always allowed.
        if (PolicyChangeSource::Manual === $message->source) {
            $plan = $this->getTeamPlan->forTeam($message->teamId);
            if (!$this->planEnforcement->canUseManagedDmarc($plan)) {
                throw new ManagedDmarcNotAvailable($plan);
            }
        }

        $domain->changeManagedPolicy(
            new ManagedDmarcPolicy($message->p, $message->sp, $message->pct),
            $message->source,
            $message->actorUserId,
            $this->clock->now(),
        );
    }
}

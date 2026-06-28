<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\ConfigureDmarcAutoRamp;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Services\Stripe\PlanEnforcement;
use App\Value\Dns\AutoRampAction;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ConfigureDmarcAutoRampHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private GetTeamPlan $getTeamPlan,
        private PlanEnforcement $planEnforcement,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConfigureDmarcAutoRamp $message): void
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            $message->domainId,
            [Uuid::fromString($message->teamId)],
        );

        if (null === $domain) {
            throw new \RuntimeException('Domain not found or not owned by team.');
        }

        // Only turning auto-drive ON requires the entitlement; off/pause/resume
        // are always allowed (they never tighten enforcement).
        if (AutoRampAction::Enable === $message->action) {
            $plan = $this->getTeamPlan->forTeam($message->teamId);
            if (!$this->planEnforcement->canUseDmarcAutoRamp($plan)) {
                throw new ManagedDmarcNotAvailable($plan);
            }
        }

        $now = $this->clock->now();

        match ($message->action) {
            AutoRampAction::Enable => $domain->enableAutoRamp($now),
            AutoRampAction::Disable => $domain->disableAutoRamp(),
            AutoRampAction::Pause => $domain->pauseAutoRamp('Paused by you', $now),
            AutoRampAction::Resume => $domain->resumeAutoRamp(),
        };
    }
}

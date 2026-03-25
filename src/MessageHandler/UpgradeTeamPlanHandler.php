<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UpgradeTeamPlan;
use App\Repository\TeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpgradeTeamPlanHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
    ) {
    }

    public function __invoke(UpgradeTeamPlan $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $team->plan = $message->plan->value;
        $team->stripeSubscriptionId = $message->stripeSubscriptionId;
        $team->stripeCustomerId = $message->stripeCustomerId;
        $team->planWarningAt = null;
    }
}

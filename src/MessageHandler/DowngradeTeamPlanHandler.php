<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DowngradeTeamPlan;
use App\Repository\TeamRepository;
use App\Value\SubscriptionPlan;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DowngradeTeamPlanHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
    ) {
    }

    public function __invoke(DowngradeTeamPlan $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $team->plan = SubscriptionPlan::Free->value;
        $team->stripeSubscriptionId = null;
        $team->planWarningAt = null;
    }
}

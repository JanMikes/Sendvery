<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ReleaseQuarantinedReportsForTeam;
use App\Message\UpgradeTeamPlan;
use App\Repository\TeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class UpgradeTeamPlanHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(UpgradeTeamPlan $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $team->plan = $message->plan->value;
        $team->stripeSubscriptionId = $message->stripeSubscriptionId;
        // Stripe sometimes fires customer.subscription.updated with no customer
        // string (or an unexpected shape); never wipe the existing ID with ''.
        if ('' !== $message->stripeCustomerId) {
            $team->stripeCustomerId = $message->stripeCustomerId;
        }
        $team->planWarningAt = null;
        if (null !== $message->billingInterval) {
            $team->billingInterval = $message->billingInterval->value;
        }

        // The bigger cap is the whole reason they upgraded: hand back the
        // reports the old cap withheld. Async so a large backlog can't stall
        // the Stripe webhook this handler usually runs inside.
        $this->commandBus->dispatch(new ReleaseQuarantinedReportsForTeam(
            teamId: $team->id,
        ));
    }
}

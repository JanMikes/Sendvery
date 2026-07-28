<?php

declare(strict_types=1);

namespace App\Command;

use App\Exceptions\TeamNotFound;
use App\Message\DowngradeTeamPlan;
use App\Message\UpgradeTeamPlan;
use App\Repository\TeamRepository;
use App\Value\SubscriptionPlan;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The staff override for the Stripe path — and therefore it has to arrive at
 * the same place the Stripe path does.
 *
 * Writing `team.plan` by hand looks equivalent and is not: a plan change is not
 * a column, it is a set of consequences, and every one of them lives in a
 * handler. Granting a bigger plan by hand used to leave the customer's
 * plan-overage reports parked until the midnight `sendvery:usage:reset`, so the
 * support ticket the grant was answering stayed open overnight. Taking a plan
 * away by hand left the Stripe subscription link dangling and left auto-ramp
 * running on managed domains for a team that had just lost the entitlement.
 *
 * Neither direction deletes anything. An upgrade hands parked reports back; a
 * downgrade freezes (see DowngradeTeamPlanHandler — pause, never loosen), and
 * anything that no longer fits the smaller cap stays quarantined for the next
 * period rather than being dropped.
 */
#[AsCommand(
    name: 'sendvery:team:set-plan',
    description: 'Set a team subscription plan directly, bypassing Stripe (staff/admin override).',
)]
final class SetTeamPlanCommand extends Command
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('team', InputArgument::REQUIRED, 'Team UUID or slug')
            ->addArgument('plan', InputArgument::REQUIRED, sprintf(
                'Plan to assign: %s',
                implode('|', array_map(static fn (SubscriptionPlan $p): string => $p->value, SubscriptionPlan::cases())),
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $teamIdentifier = (string) $input->getArgument('team');
        $planValue = (string) $input->getArgument('plan');

        $plan = SubscriptionPlan::tryFrom($planValue);
        if (null === $plan) {
            $io->error(sprintf(
                'Unknown plan "%s". Valid: %s',
                $planValue,
                implode(', ', array_map(static fn (SubscriptionPlan $p): string => $p->value, SubscriptionPlan::cases())),
            ));

            return Command::FAILURE;
        }

        try {
            $team = $this->teamRepository->get(Uuid::fromString($teamIdentifier));
        } catch (InvalidUuidStringException) {
            $team = $this->teamRepository->findBySlug($teamIdentifier);
        } catch (TeamNotFound) {
            $team = null;
        }

        if (null === $team) {
            $io->error(sprintf('Team "%s" not found (tried as UUID and slug).', $teamIdentifier));

            return Command::FAILURE;
        }

        $previousPlan = $team->plan;
        $name = $team->name;
        $slug = $team->slug;

        if (SubscriptionPlan::Free === $plan) {
            // Free is the only tier that loses an entitlement, so it is the only
            // one DowngradeTeamPlanHandler is written for; every other tier
            // change keeps managed DMARC and merely resizes the caps.
            $this->commandBus->dispatch(new DowngradeTeamPlan(teamId: $team->id));
        } else {
            // Empty Stripe identifiers mean "there is no Stripe side to this
            // change" — the handler leaves whatever is already stored alone, so
            // a grant on top of a paying customer never orphans their
            // subscription.
            $this->commandBus->dispatch(new UpgradeTeamPlan(
                teamId: $team->id,
                plan: $plan,
                stripeSubscriptionId: '',
                stripeCustomerId: '',
            ));
        }

        $io->success(sprintf(
            'Team "%s" (%s): %s → %s',
            $name,
            $slug,
            $previousPlan,
            $plan->value,
        ));

        return Command::SUCCESS;
    }
}

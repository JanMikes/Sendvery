<?php

declare(strict_types=1);

namespace App\Command;

use App\Exceptions\TeamNotFound;
use App\Repository\TeamRepository;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sendvery:team:set-plan',
    description: 'Set a team subscription plan directly, bypassing Stripe (staff/admin override).',
)]
final class SetTeamPlanCommand extends Command
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
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
        $team->plan = $plan->value;
        $team->planWarningAt = null;

        $this->entityManager->flush();

        $io->success(sprintf(
            'Team "%s" (%s): %s → %s',
            $team->name,
            $team->slug,
            $previousPlan,
            $plan->value,
        ));

        return Command::SUCCESS;
    }
}

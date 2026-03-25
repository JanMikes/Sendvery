<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Message\AddDomain;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddDomainHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamRepository $teamRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddDomain $message): void
    {
        $team = $this->teamRepository->get($message->teamId);

        $domain = new MonitoredDomain(
            id: $message->domainId,
            team: $team,
            domain: strtolower(trim($message->domainName)),
            createdAt: $this->clock->now(),
        );

        $this->entityManager->persist($domain);
    }
}

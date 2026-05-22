<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DomainOwnershipInquiry;
use App\Exceptions\DomainNotTaken;
use App\Exceptions\InquiryRateLimited;
use App\Message\CreateDomainOwnershipInquiry;
use App\Message\NotifyAdminAboutDomainOwnershipInquiry;
use App\Repository\DomainOwnershipInquiryRepository;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CreateDomainOwnershipInquiryHandler
{
    private const string RATE_LIMIT_WINDOW = '-24 hours';

    public function __construct(
        private DomainOwnershipInquiryRepository $inquiryRepository,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private UserRepository $userRepository,
        private TeamRepository $teamRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateDomainOwnershipInquiry $message): void
    {
        $now = $this->clock->now();
        $window = $now->modify(self::RATE_LIMIT_WINDOW);

        if ($this->inquiryRepository->hasRecentForUserAndDomain($message->inquiringUserId, $message->domain, $window)) {
            throw new InquiryRateLimited('You already let us know about this domain — we\'ll get back to you. Please give us a day before sending another request.');
        }

        $currentOwnership = $this->monitoredDomainRepository->findAnyByName($message->domain);
        if (null === $currentOwnership) {
            throw new DomainNotTaken('That domain isn\'t monitored by anyone, so there\'s nothing to claim.');
        }

        $inquiringUser = $this->userRepository->get($message->inquiringUserId);
        $inquiringTeam = $this->teamRepository->get($message->inquiringTeamId);

        $inquiry = new DomainOwnershipInquiry(
            id: $message->inquiryId,
            domain: $message->domain,
            inquiringUser: $inquiringUser,
            inquiringTeam: $inquiringTeam,
            currentOwnerTeam: $currentOwnership->team,
            createdAt: $now,
        );

        $this->entityManager->persist($inquiry);

        $this->commandBus->dispatch(new NotifyAdminAboutDomainOwnershipInquiry($inquiry->id));
    }
}

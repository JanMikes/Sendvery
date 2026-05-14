<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BetaAccessRequest;
use App\Message\RequestBetaAccess;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RequestBetaAccessHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestBetaAccess $message): void
    {
        $request = new BetaAccessRequest(
            id: $message->requestId,
            email: $message->email,
            name: $message->name,
            company: $message->company,
            requestedPlan: $message->requestedPlan,
            domainCount: $message->domainCount,
            message: $message->message,
            source: $message->source,
            requestedAt: $this->clock->now(),
        );

        $this->entityManager->persist($request);
    }
}

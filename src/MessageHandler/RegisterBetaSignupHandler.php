<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BetaSignup;
use App\Message\RegisterBetaSignup;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegisterBetaSignupHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RegisterBetaSignup $message): void
    {
        $signup = new BetaSignup(
            id: $message->signupId,
            email: $message->email,
            domainCount: $message->domainCount,
            painPoint: $message->painPoint,
            source: $message->source,
            signedUpAt: $this->clock->now(),
            confirmationToken: bin2hex(random_bytes(32)),
        );

        $this->entityManager->persist($signup);
    }
}

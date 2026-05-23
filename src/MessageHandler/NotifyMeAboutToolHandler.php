<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BetaSignup;
use App\Message\NotifyMeAboutTool;
use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NotifyMeAboutToolHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BetaSignupRepository $betaSignupRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(NotifyMeAboutTool $message): void
    {
        // Idempotent dedup keyed by (email, source). The DB also enforces the
        // pair via uniq_beta_signup_email_source — this check exists so a re-
        // submit returns the same "check your inbox" UX without raising and
        // without re-sending the confirmation email.
        $existing = $this->betaSignupRepository->findByEmailAndSource(
            $message->email,
            $message->source->value,
        );

        if (null !== $existing) {
            return;
        }

        // domain is parked in painPoint until BetaSignup grows a dedicated
        // column. Encoded with a `domain=` prefix so downstream analytics can
        // tell tool-notify rows apart from older beta-page rows that captured
        // free-text pain points.
        $signup = new BetaSignup(
            id: $message->signupId,
            email: $message->email,
            domainCount: 1,
            painPoint: 'domain='.$message->domain,
            source: $message->source->value,
            signedUpAt: $this->clock->now(),
            confirmationToken: bin2hex(random_bytes(32)),
        );

        $this->entityManager->persist($signup);
        // postFlush DomainEventsSubscriber drains BetaSignupCreated and the
        // existing SendBetaConfirmationEmail handler sends the confirm link.
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RevokeTeamInvitation;
use App\Repository\TeamInvitationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RevokeTeamInvitationHandler
{
    public function __construct(
        private TeamInvitationRepository $invitationRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevokeTeamInvitation $message): void
    {
        $invitation = $this->invitationRepository->get($message->invitationId);
        $invitation->revoke($this->clock->now());
    }
}

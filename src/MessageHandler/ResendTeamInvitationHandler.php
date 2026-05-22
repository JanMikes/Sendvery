<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResendTeamInvitation;
use App\Message\SendTeamInvitationEmail;
use App\Repository\TeamInvitationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ResendTeamInvitationHandler
{
    private const string INVITATION_TTL = '+14 days';

    public function __construct(
        private TeamInvitationRepository $invitationRepository,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResendTeamInvitation $message): void
    {
        $invitation = $this->invitationRepository->get($message->invitationId);
        $now = $this->clock->now();

        $invitation->resend($now, $now->modify(self::INVITATION_TTL));

        $this->commandBus->dispatch(new SendTeamInvitationEmail($invitation->id));
    }
}

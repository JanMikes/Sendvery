<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TeamInvitation;
use App\Exceptions\UserAlreadyOnTeam;
use App\Message\InviteTeammate;
use App\Message\SendTeamInvitationEmail;
use App\Repository\TeamInvitationRepository;
use App\Repository\TeamMembershipRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates the team_invitation row and dispatches the email send. The Add-time
 * checks here protect against three problems: re-inviting an existing
 * teammate, double-inviting the same email while a pending invite stands,
 * and self-invites that shouldn't make it past the form anyway.
 */
#[AsMessageHandler]
final readonly class InviteTeammateHandler
{
    private const int TOKEN_BYTES = 32; // 64 hex chars
    private const string INVITATION_TTL = '+14 days';

    public function __construct(
        private TeamRepository $teamRepository,
        private UserRepository $userRepository,
        private TeamMembershipRepository $membershipRepository,
        private TeamInvitationRepository $invitationRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(InviteTeammate $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $invitedBy = $this->userRepository->get($message->invitedByUserId);

        $normalizedEmail = strtolower(trim($message->invitedEmail));

        // Already on this team?
        $existingMember = $this->userRepository->findByEmail($normalizedEmail);
        if (null !== $existingMember
            && null !== $this->membershipRepository->findMembership($existingMember->id, $team->id)) {
            throw new UserAlreadyOnTeam(sprintf('%s is already a member of this team.', $normalizedEmail));
        }

        // Already invited (and the previous invite still pending)?
        $existingInvite = $this->invitationRepository->findActiveForTeamAndEmail($team->id, $normalizedEmail);
        if (null !== $existingInvite) {
            throw new UserAlreadyOnTeam(sprintf('There is already a pending invitation for %s. Resend or revoke it first.', $normalizedEmail));
        }

        $now = $this->clock->now();

        $invitation = new TeamInvitation(
            id: $message->invitationId,
            team: $team,
            invitedEmail: $normalizedEmail,
            invitedBy: $invitedBy,
            role: $message->role,
            invitationToken: bin2hex(random_bytes(self::TOKEN_BYTES)),
            sentAt: $now,
            expiresAt: $now->modify(self::INVITATION_TTL),
        );

        $this->entityManager->persist($invitation);

        $this->commandBus->dispatch(new SendTeamInvitationEmail($invitation->id));
    }
}

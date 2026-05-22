<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TeamMembership;
use App\Exceptions\InvitationEmailMismatch;
use App\Exceptions\InvitationNoLongerAcceptable;
use App\Exceptions\TeamInvitationNotFound;
use App\Exceptions\UserAlreadyOnTeam;
use App\Message\AcceptTeamInvitation;
use App\Repository\TeamInvitationRepository;
use App\Repository\TeamMembershipRepository;
use App\Repository\UserRepository;
use App\Services\IdentityProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns a valid invitation + the logged-in user into a TeamMembership row.
 *
 * Refuses if:
 *   - the invitation is expired, revoked, or already accepted;
 *   - the logged-in user's email doesn't match the invited address;
 *   - the user is already on this team (idempotent — different error code so
 *     the controller can show a friendly "you're already a member" page).
 */
#[AsMessageHandler]
final readonly class AcceptTeamInvitationHandler
{
    public function __construct(
        private TeamInvitationRepository $invitationRepository,
        private UserRepository $userRepository,
        private TeamMembershipRepository $membershipRepository,
        private IdentityProvider $identityProvider,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AcceptTeamInvitation $message): void
    {
        $invitation = $this->invitationRepository->findByToken($message->invitationToken);
        if (null === $invitation) {
            throw new TeamInvitationNotFound('Invitation not found.');
        }

        $now = $this->clock->now();

        if (!$invitation->isAcceptable($now)) {
            throw new InvitationNoLongerAcceptable('This invitation can no longer be accepted.');
        }

        $user = $this->userRepository->get($message->acceptingUserId);

        if (strtolower($user->email) !== $invitation->invitedEmail) {
            throw new InvitationEmailMismatch(sprintf('This invitation is for %s but you are signed in as %s.', $invitation->invitedEmail, $user->email));
        }

        if (null !== $this->membershipRepository->findMembership($user->id, $invitation->team->id)) {
            throw new UserAlreadyOnTeam(sprintf('You are already a member of %s.', $invitation->team->name));
        }

        $membership = new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $invitation->team,
            role: $invitation->role,
            joinedAt: $now,
        );

        $this->entityManager->persist($membership);

        $invitation->accept($now);
    }
}

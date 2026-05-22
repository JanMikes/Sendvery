<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Message\AcceptTeamInvitation;
use App\Repository\TeamInvitationRepository;
use App\Services\DashboardContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Bridges the magic-link auth flow with the team-invitation flow.
 *
 * When a user clicks an invitation link while logged out, the controller
 * stashes the invitation token in the session and kicks off magic-link
 * sign-in. After auth succeeds, this listener picks up the token, dispatches
 * AcceptTeamInvitation, and clears the session marker so a future logout +
 * login doesn't re-trigger.
 */
#[AsEventListener]
final readonly class CompleteTeamInvitationOnLogin
{
    public const string SESSION_KEY = 'pending_team_invitation_token';

    public function __construct(
        private RequestStack $requestStack,
        private MessageBusInterface $commandBus,
        private TeamInvitationRepository $invitationRepository,
        private DashboardContext $dashboardContext,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $session = $this->requestStack->getSession();
        $token = $session->get(self::SESSION_KEY);

        if (!is_string($token) || '' === $token) {
            return;
        }

        $session->remove(self::SESSION_KEY);

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Resolve the team BEFORE dispatching so we know which team to switch
        // into. The dispatch may consume the invitation row.
        $invitation = $this->invitationRepository->findByToken($token);

        try {
            $this->commandBus->dispatch(new AcceptTeamInvitation(
                invitationToken: $token,
                acceptingUserId: $user->id,
            ));
        } catch (\Throwable) {
            // Best-effort: if the invitation flow fails (expired, mismatch,
            // already a member), the auth itself still succeeded. The
            // AcceptTeamInvitationController will surface a clear error if the
            // user revisits the invite link explicitly.
            return;
        }

        if (null !== $invitation) {
            try {
                $this->dashboardContext->setActiveTeam($invitation->team->id);
            } catch (\DomainException) {
                // The accept succeeded but DashboardContext can't see the new
                // membership yet (Doctrine identity map). Falling back to the
                // first membership on the next request is safe — the user
                // just won't land in the new team automatically.
            }
        }
    }
}

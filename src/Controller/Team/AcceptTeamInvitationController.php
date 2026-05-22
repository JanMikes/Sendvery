<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Entity\User;
use App\EventListener\CompleteTeamInvitationOnLogin;
use App\Exceptions\InvitationEmailMismatch;
use App\Exceptions\InvitationNoLongerAcceptable;
use App\Exceptions\UserAlreadyOnTeam;
use App\Message\AcceptTeamInvitation;
use App\Repository\TeamInvitationRepository;
use App\Services\DashboardContext;
use App\Value\TeamInvitationStatus;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page the invitee hits from the email link.
 *
 *  - Token unknown / revoked / expired → friendly error page.
 *  - User signed in with the invited email → accept inline, send to /app.
 *  - User signed in with a different email → "wrong account" page.
 *  - Not signed in → stash the token in session, redirect through magic-link
 *    sign-in. The CompleteTeamInvitationOnLogin listener finalises after auth.
 */
final class AcceptTeamInvitationController extends AbstractController
{
    public function __construct(
        private readonly TeamInvitationRepository $invitationRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly DashboardContext $dashboardContext,
    ) {
    }

    #[Route('/team/invitation/{token}', name: 'team_accept_invitation', methods: ['GET'])]
    public function __invoke(string $token, Request $request): Response
    {
        $invitation = $this->invitationRepository->findByToken($token);

        if (null === $invitation) {
            return $this->render('team/invitation_invalid.html.twig', [
                'reason' => 'This invitation link isn\'t valid. Ask the person who invited you to send a new one.',
            ]);
        }

        if (TeamInvitationStatus::Revoked === $invitation->status) {
            return $this->render('team/invitation_invalid.html.twig', [
                'reason' => 'This invitation has been revoked. Ask your teammate to send you a new one.',
            ]);
        }

        if (TeamInvitationStatus::Accepted === $invitation->status) {
            return $this->render('team/invitation_invalid.html.twig', [
                'reason' => 'This invitation has already been used. Sign in to access the team.',
            ]);
        }

        $now = $this->clock->now();
        if ($invitation->isExpired($now)) {
            return $this->render('team/invitation_invalid.html.twig', [
                'reason' => 'This invitation has expired. Ask your teammate to send a fresh one.',
            ]);
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            // Stash the token so the post-login listener can complete the join.
            $request->getSession()->set(CompleteTeamInvitationOnLogin::SESSION_KEY, $token);

            return $this->redirectToRoute('auth_login', [
                'email' => $invitation->invitedEmail,
                'invited' => '1',
            ]);
        }

        if (strtolower($user->email) !== $invitation->invitedEmail) {
            return $this->render('team/invitation_email_mismatch.html.twig', [
                'invitedEmail' => $invitation->invitedEmail,
                'currentEmail' => $user->email,
                'teamName' => $invitation->team->name,
            ]);
        }

        try {
            $this->commandBus->dispatch(new AcceptTeamInvitation(
                invitationToken: $token,
                acceptingUserId: $user->id,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof UserAlreadyOnTeam) {
                return $this->redirectToRoute('dashboard_overview');
            }

            $reason = $previous instanceof InvitationNoLongerAcceptable || $previous instanceof InvitationEmailMismatch
                ? $previous->getMessage()
                : 'We couldn\'t add you to the team. Please try again or contact your teammate.';

            return $this->render('team/invitation_invalid.html.twig', ['reason' => $reason]);
        }

        // Newly-joined team becomes active so the "Go to dashboard" link on
        // the confirmation page (and the next request) shows the team the
        // user just chose to join, not whichever team was active before.
        $this->dashboardContext->setActiveTeam($invitation->team->id);

        return $this->render('team/invitation_accepted.html.twig', [
            'teamName' => $invitation->team->name,
        ]);
    }
}

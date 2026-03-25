<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Repository\BetaInvitationRepository;
use App\Value\InvitationStatus;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AcceptInvitationController extends AbstractController
{
    public function __construct(
        private readonly BetaInvitationRepository $invitationRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/invite/{token}', name: 'auth_accept_invitation', methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        $invitation = $this->invitationRepository->findByToken($token);

        if (null === $invitation) {
            return $this->render('auth/invitation_invalid.html.twig', [
                'reason' => 'This invitation link is invalid.',
            ]);
        }

        $now = $this->clock->now();

        if (InvitationStatus::Accepted === $invitation->status) {
            return $this->render('auth/invitation_invalid.html.twig', [
                'reason' => 'This invitation has already been used. You can log in below.',
            ]);
        }

        if ($invitation->isExpired($now)) {
            return $this->render('auth/invitation_invalid.html.twig', [
                'reason' => 'This invitation has expired. Please request a new one.',
            ]);
        }

        // Mark invitation as accepted — actual user creation happens via magic link auth
        $invitation->accept($now);

        // Redirect to magic link login pre-filled with the invitation email
        return $this->redirectToRoute('auth_login', [
            'email' => $invitation->email,
            'invited' => '1',
        ]);
    }
}

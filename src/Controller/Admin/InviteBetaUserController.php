<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Message\InviteBetaUser;
use App\Services\IdentityProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class InviteBetaUserController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly IdentityProvider $identityProvider,
    ) {
    }

    #[Route('/app/admin/invite', name: 'admin_invite_beta_user', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        assert($user instanceof User);

        if ($request->isMethod('POST')) {
            $emailsRaw = $request->request->getString('emails');
            $parts = preg_split('/[\n,]+/', $emailsRaw);
            $emails = array_filter(
                array_map('trim', false !== $parts ? $parts : []),
                static fn (string $email) => false !== filter_var($email, FILTER_VALIDATE_EMAIL),
            );

            if ([] === $emails) {
                return $this->render('dashboard/admin_invite.html.twig', [
                    'error' => 'Please enter at least one valid email address.',
                    'emailsRaw' => $emailsRaw,
                ]);
            }

            foreach ($emails as $email) {
                $this->messageBus->dispatch(new InviteBetaUser(
                    invitationId: $this->identityProvider->nextIdentity(),
                    email: strtolower($email),
                    invitedById: $user->id,
                ));
            }

            return $this->render('dashboard/admin_invite.html.twig', [
                'success' => sprintf('%d invitation(s) sent successfully.', count($emails)),
                'emailsRaw' => '',
            ]);
        }

        return $this->render('dashboard/admin_invite.html.twig', [
            'emailsRaw' => '',
        ]);
    }
}

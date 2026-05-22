<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Entity\User;
use App\Exceptions\UserAlreadyOnTeam;
use App\Message\InviteTeammate;
use App\Repository\TeamRepository;
use App\Security\TeamVoter;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use App\Value\TeamRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class InviteTeammateController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/app/team/invite', name: 'team_invite_teammate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $team = $this->teamRepository->get($teamId);
        $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team);

        $email = strtolower(trim($request->request->getString('email')));
        $roleValue = $request->request->getString('role', TeamRole::Member->value);

        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(message: 'Enter the teammate\'s email address.'),
            new Assert\Email(message: 'That doesn\'t look like a valid email address.'),
        ]);

        if (count($violations) > 0) {
            $first = $violations->get(0);
            $this->addFlash('team_error', (string) $first->getMessage());

            return $this->redirectToRoute('team_settings');
        }

        $role = TeamRole::tryFrom($roleValue);
        if (null === $role || !in_array($role, [TeamRole::Member, TeamRole::Admin], true)) {
            $this->addFlash('team_error', 'Please pick a valid role for the new teammate.');

            return $this->redirectToRoute('team_settings');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $this->commandBus->dispatch(new InviteTeammate(
                invitationId: $this->identityProvider->nextIdentity(),
                teamId: $teamId,
                invitedByUserId: $currentUser->id,
                invitedEmail: $email,
                role: $role,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            $message = $previous instanceof UserAlreadyOnTeam
                ? $previous->getMessage()
                : 'Something went wrong sending the invitation. Please try again.';
            $this->addFlash('team_error', $message);

            return $this->redirectToRoute('team_settings');
        }

        $this->addFlash('team_success', sprintf('Invitation sent to %s.', $email));

        return $this->redirectToRoute('team_settings');
    }
}

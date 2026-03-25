<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Message\ConnectMailbox;
use App\Repository\TeamMembershipRepository;
use App\Services\IdentityProvider;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingIngestionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly TeamMembershipRepository $teamMembershipRepository,
    ) {
    }

    #[Route('/app/onboarding/ingestion', name: 'onboarding_ingestion', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->onboardingCompletedAt) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $errors = [];
        $method = $request->request->getString('method');

        if ($request->isMethod('POST')) {
            if ('forward' === $method) {
                return $this->redirectToRoute('onboarding_complete');
            }

            if ('mailbox' === $method) {
                $host = trim($request->request->getString('host'));
                $port = $request->request->getInt('port', 993);
                $username = trim($request->request->getString('username'));
                $password = $request->request->getString('password');
                $encryption = $request->request->getString('encryption', 'ssl');

                if ('' === $host || '' === $username || '' === $password) {
                    $errors[] = 'Please fill in all connection fields.';
                } else {
                    $memberships = $this->teamMembershipRepository->findForUser($user->id);
                    $teamId = $memberships[0]->team->id;

                    $this->commandBus->dispatch(new ConnectMailbox(
                        connectionId: $this->identityProvider->nextIdentity(),
                        teamId: $teamId,
                        domainId: null,
                        type: MailboxType::ImapUser,
                        host: $host,
                        port: $port,
                        username: $username,
                        password: $password,
                        encryption: MailboxEncryption::from($encryption),
                    ));

                    return $this->redirectToRoute('onboarding_complete');
                }
            }
        }

        return $this->render('onboarding/ingestion.html.twig', [
            'errors' => $errors,
        ]);
    }
}

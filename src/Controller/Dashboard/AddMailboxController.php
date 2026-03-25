<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\FormData\AddMailboxData;
use App\Message\ConnectMailbox;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AddMailboxController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
        private readonly DashboardContext $dashboardContext,
    ) {
    }

    #[Route('/app/mailboxes/add', name: 'dashboard_mailbox_add', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new AddMailboxData();
        $errors = [];

        if ($request->isMethod('POST')) {
            $data->host = trim($request->request->getString('host'));
            $data->port = $request->request->getInt('port', 993);
            $data->username = trim($request->request->getString('username'));
            $data->password = $request->request->getString('password');
            $data->encryption = $request->request->getString('encryption', 'ssl');
            $data->type = $request->request->getString('type', 'imap_user');

            $violations = $this->validator->validate($data);

            if (count($violations) > 0) {
                foreach ($violations as $violation) {
                    $errors[] = (string) $violation->getMessage();
                }
            } else {
                $connectionId = $this->identityProvider->nextIdentity();
                $teamId = $this->dashboardContext->getTeamId();

                $this->commandBus->dispatch(new ConnectMailbox(
                    connectionId: $connectionId,
                    teamId: $teamId,
                    domainId: null,
                    type: MailboxType::from($data->type),
                    host: $data->host,
                    port: $data->port,
                    username: $data->username,
                    password: $data->password,
                    encryption: MailboxEncryption::from($data->encryption),
                ));

                return $this->redirectToRoute('dashboard_mailboxes');
            }
        }

        return $this->render('dashboard/mailbox_add.html.twig', [
            'data' => $data,
            'errors' => $errors,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Message\ConnectMailbox;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamMembershipRepository;
use App\Services\Dns\DmarcChecker;
use App\Services\IdentityProvider;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingIngestionController extends AbstractController
{
    private const string REPORT_ADDRESS = 'reports@sendvery.com';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly TeamMembershipRepository $teamMembershipRepository,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly DmarcChecker $dmarcChecker,
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

        $memberships = $this->teamMembershipRepository->findForUser($user->id);
        $teamId = $memberships[0]->team->id;

        $requestedDomain = strtolower(trim($request->query->getString('domain')));
        $primaryDomain = '' !== $requestedDomain
            ? $this->monitoredDomainRepository->findByDomain($requestedDomain, $teamId)
            : null;

        if (null === $primaryDomain) {
            $primaryDomain = $this->monitoredDomainRepository->findLatestForTeam($teamId);
        }

        $domainName = null;
        $ruaInstruction = null;

        if (null !== $primaryDomain) {
            $domainName = $primaryDomain->domain;
            $dmarcCheck = $this->dmarcChecker->check($primaryDomain->domain);
            $ruaInstruction = DmarcRuaInstruction::build($dmarcCheck->rawRecord, self::REPORT_ADDRESS);
        }

        return $this->render('onboarding/ingestion.html.twig', [
            'errors' => $errors,
            'domainName' => $domainName,
            'ruaInstruction' => $ruaInstruction,
            'reportAddress' => self::REPORT_ADDRESS,
        ]);
    }
}

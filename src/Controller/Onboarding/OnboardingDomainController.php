<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\FormData\AddDomainData;
use App\Message\AddDomain;
use App\Repository\TeamMembershipRepository;
use App\Services\Dns\DkimChecker;
use App\Services\Dns\DmarcChecker;
use App\Services\Dns\SpfChecker;
use App\Services\IdentityProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OnboardingDomainController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
        private readonly TeamMembershipRepository $teamMembershipRepository,
        private readonly SpfChecker $spfChecker,
        private readonly DkimChecker $dkimChecker,
        private readonly DmarcChecker $dmarcChecker,
    ) {
    }

    #[Route('/app/onboarding/domain', name: 'onboarding_domain', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->onboardingCompletedAt) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $data = new AddDomainData();
        $errors = [];
        $dnsResults = null;

        if ($request->isMethod('POST')) {
            $data->domainName = trim($request->request->getString('domain_name'));

            $violations = $this->validator->validate($data);

            if (count($violations) > 0) {
                foreach ($violations as $violation) {
                    $errors[] = (string) $violation->getMessage();
                }
            } else {
                $memberships = $this->teamMembershipRepository->findForUser($user->id);
                $teamId = $memberships[0]->team->id;

                $domainId = $this->identityProvider->nextIdentity();

                $this->commandBus->dispatch(new AddDomain(
                    domainId: $domainId,
                    teamId: $teamId,
                    domainName: $data->domainName,
                ));

                $dnsResults = [
                    'spf' => $this->spfChecker->check($data->domainName),
                    'dkim' => $this->dkimChecker->check($data->domainName),
                    'dmarc' => $this->dmarcChecker->check($data->domainName),
                ];

                if ($request->request->has('continue')) {
                    return $this->redirectToRoute('onboarding_ingestion');
                }
            }
        }

        return $this->render('onboarding/domain.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'dnsResults' => $dnsResults,
        ]);
    }
}

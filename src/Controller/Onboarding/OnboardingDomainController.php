<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\FormData\AddDomainData;
use App\Message\AddDomain;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamMembershipRepository;
use App\Services\Dns\DkimChecker;
use App\Services\Dns\DmarcChecker;
use App\Services\Dns\SpfChecker;
use App\Services\IdentityProvider;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly EntityManagerInterface $entityManager,
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

        $memberships = $this->teamMembershipRepository->findForUser($user->id);
        $teamId = $memberships[0]->team->id;

        $data = new AddDomainData();
        $errors = [];
        $dnsResults = null;
        $hasExistingDomain = false;

        if ($request->isMethod('POST')) {
            $data->domainName = $this->normalizeDomainInput($request->request->getString('domain_name'));

            $violations = $this->validator->validate($data);

            if (count($violations) > 0) {
                foreach ($violations as $violation) {
                    $errors[] = (string) $violation->getMessage();
                }
            } else {
                // Enforce the post-onboarding invariant of one domain per team:
                // if the team already has a domain, rename it in place instead of
                // appending a second row when the user submits a different name.
                $existing = $this->monitoredDomainRepository->findLatestForTeam($teamId);

                if (null === $existing) {
                    $this->commandBus->dispatch(new AddDomain(
                        domainId: $this->identityProvider->nextIdentity(),
                        teamId: $teamId,
                        domainName: $data->domainName,
                    ));
                } elseif ($existing->domain !== $data->domainName) {
                    $existing->domain = $data->domainName;
                    $this->entityManager->flush();
                }

                return $this->redirectToRoute('onboarding_domain');
            }
        } else {
            $existing = $this->monitoredDomainRepository->findLatestForTeam($teamId);

            if (null !== $existing) {
                $data->domainName = $existing->domain;
                $hasExistingDomain = true;
                $dnsResults = [
                    'spf' => $this->spfChecker->check($existing->domain),
                    'dkim' => $this->dkimChecker->check($existing->domain),
                    'dmarc' => $this->dmarcChecker->check($existing->domain),
                ];
            }
        }

        return $this->render('onboarding/domain.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'dnsResults' => $dnsResults,
            'hasExistingDomain' => $hasExistingDomain,
        ]);
    }

    private function normalizeDomainInput(string $input): string
    {
        $value = strtolower(trim($input));
        $value = (string) preg_replace('#^https?://#', '', $value);
        $value = (string) preg_replace('#^www\.#', '', $value);

        return $value;
    }
}

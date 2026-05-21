<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\FormData\AddDomainData;
use App\Message\AddDomain;
use App\Message\CheckDomainDns;
use App\MessageHandler\CheckDomainDnsHandler;
use App\Repository\DnsCheckResultRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\IdentityProvider;
use App\Services\TeamProvisioner;
use App\Value\DnsCheckType;
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
        private readonly TeamProvisioner $teamProvisioner,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CheckDomainDnsHandler $checkDomainDnsHandler,
        private readonly DnsCheckResultRepository $dnsCheckResultRepository,
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

        $teamId = $this->teamProvisioner->provisionForUser($user)->id;

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

                // Use the same handler the daily cron + dashboard re-verify use,
                // so dns_check_result rows are written and downstream queries
                // (GetDomainVerificationStatus, evaluator) see consistent state.
                ($this->checkDomainDnsHandler)(new CheckDomainDns(domainId: $existing->id));
                $this->entityManager->flush();

                $dnsResults = [
                    'spf' => $this->dnsCheckResultRepository->findLatestForDomainAndType($existing->id, DnsCheckType::Spf),
                    'dkim' => $this->dnsCheckResultRepository->findLatestForDomainAndType($existing->id, DnsCheckType::Dkim),
                    'dmarc' => $this->dnsCheckResultRepository->findLatestForDomainAndType($existing->id, DnsCheckType::Dmarc),
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

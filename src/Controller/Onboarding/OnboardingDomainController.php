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
use App\Services\Stripe\PlanEnforcement;
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
        private readonly PlanEnforcement $planEnforcement,
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
        $joinedExistingTeam = null;

        if ($request->isMethod('POST')) {
            $data->domainName = $this->normalizeDomainInput($request->request->getString('domain_name'));

            $violations = $this->validator->validate($data);

            if (count($violations) > 0) {
                foreach ($violations as $violation) {
                    $errors[] = (string) $violation->getMessage();
                }
            } else {
                // Hard-block when another team has already claimed this name.
                // The /app/domain-taken page guides the user toward joining
                // that team or pinging admin if they're the rightful owner.
                $conflict = $this->monitoredDomainRepository->findAnyByName($data->domainName);
                if (null !== $conflict && $conflict->team->id->toString() !== $teamId->toString()) {
                    return $this->redirectToRoute('domain_taken', ['domain' => $data->domainName]);
                }

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
                    // The rename above exists so someone can correct a typo in the
                    // domain they just typed — and it is only theirs to correct
                    // while they are the team's only member.
                    //
                    // Accepting an invitation does not set onboardingCompletedAt,
                    // so an invited teammate is walked through this step against a
                    // team that already monitors something, and the stepper links
                    // step 2. Renaming there re-points a colleague's domain — id,
                    // DMARC reports, alerts and DNS history intact — at a name its
                    // owner never chose, and silently stops the original from being
                    // monitored, because inbound reports route by name. When the
                    // team holds several domains it also collides with the
                    // system-wide unique index on lower(domain) and 500s.
                    if (1 === $this->planEnforcement->getTeamMemberCount($teamId->toString())) {
                        $existing->domain = $data->domainName;
                        $this->entityManager->flush();
                    } else {
                        $joinedExistingTeam = $existing->domain;
                    }
                }

                if (null === $joinedExistingTeam) {
                    return $this->redirectToRoute('onboarding_domain');
                }
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
            'joinedExistingTeam' => $joinedExistingTeam,
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

<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Message\CheckDomainDns;
use App\MessageHandler\CheckDomainDnsHandler;
use App\Repository\DnsCheckResultRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\TeamProvisioner;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingIngestionVerifyController extends AbstractController
{
    public function __construct(
        private readonly TeamProvisioner $teamProvisioner,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly CheckDomainDnsHandler $checkDomainDnsHandler,
        private readonly DnsCheckResultRepository $dnsCheckResultRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/app/onboarding/ingestion/verify', name: 'onboarding_ingestion_verify', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->onboardingCompletedAt) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $teamId = $this->teamProvisioner->provisionForUser($user)->id;
        $domain = $this->monitoredDomainRepository->findLatestForTeam($teamId);

        if (null === $domain) {
            return $this->redirectToRoute('onboarding_domain');
        }

        // Same handler as the daily cron and dashboard re-verify — one code path for
        // DNS state means step 4 and dashboard always agree with what we tell the user here.
        ($this->checkDomainDnsHandler)(new CheckDomainDns(domainId: $domain->id));
        $this->entityManager->flush();

        $dmarcResult = $this->dnsCheckResultRepository->findLatestForDomainAndType($domain->id, DnsCheckType::Dmarc);

        return $this->render('onboarding/_verify_panel.html.twig', [
            'domainName' => $domain->domain,
            'dmarcResult' => $dmarcResult,
        ]);
    }
}

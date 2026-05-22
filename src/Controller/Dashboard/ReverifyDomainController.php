<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\CheckDomainDns;
use App\MessageHandler\CheckDomainDnsHandler;
use App\Repository\MonitoredDomainRepository;
use App\Services\DashboardContext;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReverifyDomainController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CheckDomainDnsHandler $checkDomainDnsHandler,
    ) {
    }

    #[Route('/app/domains/{id}/reverify', name: 'dashboard_domain_reverify', methods: ['POST'])]
    public function __invoke(string $id): Response
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        // Run the same handler the daily cron uses so the dns_check_result row
        // is written and the verification status query reflects today's state.
        ($this->checkDomainDnsHandler)(new CheckDomainDns(domainId: $domain->id));
        $this->entityManager->flush();

        return $this->redirectToRoute('dashboard_overview');
    }
}

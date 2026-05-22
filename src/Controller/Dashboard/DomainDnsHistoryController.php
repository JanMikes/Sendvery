<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainDnsHistory;
use App\Repository\DnsCheckResultRepository;
use App\Services\DashboardContext;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\DnsCheckType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DomainDnsHistoryController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainDnsHistory $getDomainDnsHistory,
        private readonly DnsCheckResultRepository $dnsCheckResultRepository,
        private readonly ReportAddressProvider $reportAddressProvider,
    ) {
    }

    #[Route('/app/domains/{id}/dns-history', name: 'dashboard_domain_dns_history')]
    public function __invoke(string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($id, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $history = $this->getDomainDnsHistory->forDomain($id, $teamIds);

        $latestDmarcCheck = $this->dnsCheckResultRepository->findLatestForDomainAndType(
            Uuid::fromString($id),
            DnsCheckType::Dmarc,
        );

        $ruaInstruction = DmarcRuaInstruction::build(
            $latestDmarcCheck?->rawRecord,
            $this->reportAddressProvider->get(),
        );

        return $this->render('dashboard/domain_dns_history.html.twig', [
            'domain' => $domain,
            'history' => $history,
            'ruaInstruction' => $ruaInstruction,
        ]);
    }
}

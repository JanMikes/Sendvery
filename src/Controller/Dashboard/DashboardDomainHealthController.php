<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainHealthHistory;
use App\Repository\DnsCheckResultRepository;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\DnsCheckType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardDomainHealthController extends AbstractController
{
    public function __construct(
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainHealthHistory $getDomainHealthHistory,
        private readonly DnsCheckResultRepository $dnsCheckResultRepository,
        private readonly ReportAddressProvider $reportAddressProvider,
    ) {
    }

    #[Route('/app/domains/{id}/health', name: 'dashboard_domain_health')]
    public function __invoke(string $id): Response
    {
        $domain = $this->getDomainDetail->forDomain($id);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $latest = $this->getDomainHealthHistory->latestForDomain($id);
        $history = $this->getDomainHealthHistory->forDomain($id);

        $latestDmarcCheck = $this->dnsCheckResultRepository->findLatestForDomainAndType(
            Uuid::fromString($id),
            DnsCheckType::Dmarc,
        );

        $ruaInstruction = DmarcRuaInstruction::build(
            $latestDmarcCheck?->rawRecord,
            $this->reportAddressProvider->get(),
        );

        $trendChartConfig = null;
        if (count($history) > 1) {
            $reversed = array_reverse($history);
            $trendChartConfig = [
                'chart' => ['type' => 'line', 'height' => 280],
                'series' => [
                    ['name' => 'Overall Score', 'data' => array_map(static fn ($h) => $h->score, $reversed)],
                ],
                'xaxis' => [
                    'categories' => array_map(static fn ($h) => $h->checkedAt, $reversed),
                    'type' => 'datetime',
                ],
                'colors' => ['#6366f1'],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'yaxis' => ['min' => 0, 'max' => 100],
                'dataLabels' => ['enabled' => false],
            ];
        }

        return $this->render('dashboard/domain_health.html.twig', [
            'domain' => $domain,
            'latest' => $latest,
            'history' => $history,
            'trendChartConfig' => $trendChartConfig,
            'ruaInstruction' => $ruaInstruction,
        ]);
    }
}

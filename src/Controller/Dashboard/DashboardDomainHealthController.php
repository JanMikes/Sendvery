<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainHealthHistory;
use App\Query\GetDomainWorkspaceTabCounts;
use App\Repository\DnsCheckResultRepository;
use App\Services\DashboardContext;
use App\Services\Dns\DnsRecordRecommender;
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
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainHealthHistory $getDomainHealthHistory,
        private readonly DnsCheckResultRepository $dnsCheckResultRepository,
        private readonly ReportAddressProvider $reportAddressProvider,
        private readonly DnsRecordRecommender $dnsRecordRecommender,
        private readonly GetDomainWorkspaceTabCounts $getDomainWorkspaceTabCounts,
    ) {
    }

    #[Route('/app/domains/{id}/health', name: 'dashboard_domain_health')]
    public function __invoke(string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($id, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $latest = $this->getDomainHealthHistory->latestForDomain($id, $teamIds);
        $history = $this->getDomainHealthHistory->forDomain($id, $teamIds);

        $domainUuid = Uuid::fromString($id);

        // TASK-095: load every per-category DNS check in one go so the
        // recommender can reason over the full picture (e.g. cross-reference
        // an SPF "no record" with the DMARC state).
        $latestByType = [
            DnsCheckType::Spf->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Spf),
            DnsCheckType::Dkim->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Dkim),
            DnsCheckType::Dmarc->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Dmarc),
            DnsCheckType::Mx->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Mx),
        ];

        $ruaInstruction = DmarcRuaInstruction::build(
            $latestByType[DnsCheckType::Dmarc->value]?->rawRecord,
            $this->reportAddressProvider->get(),
        );

        $dnsRecommendations = $this->dnsRecordRecommender->recommendForDomain($domain->domainName, $latestByType);

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

        $tabCounts = $this->getDomainWorkspaceTabCounts->forDomain($id)->toTwigArray();

        return $this->render('dashboard/domain_health.html.twig', [
            'domain' => $domain,
            'latest' => $latest,
            'history' => $history,
            'trendChartConfig' => $trendChartConfig,
            'ruaInstruction' => $ruaInstruction,
            'dnsRecommendations' => $dnsRecommendations,
            'tabCounts' => $tabCounts,
        ]);
    }
}

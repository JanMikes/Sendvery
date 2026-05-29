<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetReportDetail;
use App\Query\GetReportSenderGroups;
use App\Repository\AiInsightRepository;
use App\Repository\DmarcReportRepository;
use App\Services\Ai\AiInsightCacheKey;
use App\Services\Ai\AiInsightContent;
use App\Services\Ai\Analysis\ReportInsightAnalyzer;
use App\Services\Ai\Analysis\RoutineReportClassifier;
use App\Services\DashboardContext;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowReportDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetReportDetail $getReportDetail,
        private readonly GetReportSenderGroups $getReportSenderGroups,
        private readonly DmarcReportRepository $reportRepository,
        private readonly ReportInsightAnalyzer $analyzer,
        private readonly RoutineReportClassifier $routineClassifier,
        private readonly AiInsightRepository $insights,
        private readonly PlanEnforcement $enforcement,
        private readonly PlanLimits $limits,
    ) {
    }

    #[Route('/app/reports/{id}', name: 'dashboard_report_detail')]
    public function __invoke(string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $report = $this->getReportDetail->forReport($id, $teamIds);

        if (null === $report) {
            throw $this->createNotFoundException('Report not found.');
        }

        $senderGroups = $this->getReportSenderGroups->forReport($id, $teamIds);

        $totalMessages = 0;
        $passMessages = 0;
        foreach ($report->records as $record) {
            $totalMessages += $record->count;
            if ('pass' === $record->dkimResult || 'pass' === $record->spfResult) {
                $passMessages += $record->count;
            }
        }
        $failMessages = $totalMessages - $passMessages;

        $donutConfig = [
            'chart' => ['type' => 'donut', 'height' => 200],
            'series' => [$passMessages, $failMessages],
            'labels' => ['Pass', 'Fail'],
            'colors' => ['#34d399', '#f87171'],
            'legend' => ['position' => 'bottom'],
            'dataLabels' => ['enabled' => true],
        ];

        return $this->render('dashboard/report_detail.html.twig', [
            'report' => $report,
            'totalMessages' => $totalMessages,
            'passMessages' => $passMessages,
            'failMessages' => $failMessages,
            'donutConfig' => $donutConfig,
            'senderGroups' => $senderGroups,
            ...$this->aiState($id),
        ]);
    }

    /**
     * Resolve the AI-explanation surface for this report. Routine reports get an
     * instant templated explanation for free (no button, no quota, no API call);
     * the "Explain" button — which spends quota — appears only for non-routine
     * reports without a cached result. Non-AI plans see nothing.
     *
     * @return array{aiEnabled: bool, cachedExplanation: string|null, routineExplanation: string|null, showExplainButton: bool, aiRemaining: int, aiLimit: int}
     */
    private function aiState(string $reportId): array
    {
        // Scope to the team that owns the report (may differ from the active team
        // for a user who belongs to several teams). The report is known-visible here.
        $team = $this->reportRepository->get(Uuid::fromString($reportId))->monitoredDomain->team;
        $teamId = $team->id;
        $plan = $team->getSubscriptionPlan();

        $state = [
            'aiEnabled' => $plan->hasAi(),
            'cachedExplanation' => null,
            'routineExplanation' => null,
            'showExplainButton' => false,
            'aiRemaining' => 0,
            'aiLimit' => 0,
        ];

        if (!$plan->hasAi()) {
            return $state;
        }

        $cached = $this->insights->findByCacheKey(AiInsightCacheKey::reportExplanation($reportId));
        if (null !== $cached) {
            $state['cachedExplanation'] = AiInsightContent::reportExplanation($cached->content)->explanation;
        } else {
            $facts = $this->analyzer->analyzeReport(Uuid::fromString($reportId), $teamId);
            if (null !== $facts && $this->routineClassifier->isRoutine($facts)) {
                $state['routineExplanation'] = $this->routineClassifier->buildTemplatedExplanation($facts)->explanation;
            } else {
                $state['showExplainButton'] = true;
            }
        }

        $state['aiRemaining'] = $this->enforcement->getRemainingAiQuota($teamId->toString(), $plan);
        $state['aiLimit'] = $this->limits->getOnDemandAiQuota($plan);

        return $state;
    }
}

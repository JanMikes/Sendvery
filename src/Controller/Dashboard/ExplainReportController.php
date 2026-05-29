<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\DmarcReport;
use App\Exceptions\AiNotEnabledForPlan;
use App\Exceptions\AiQuotaExceeded;
use App\Exceptions\ReportNotAnalyzable;
use App\Repository\DmarcReportRepository;
use App\Services\Ai\AiInsightsService;
use App\Services\DashboardContext;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-demand "Explain this report". Posts from the report detail page and swaps
 * the explanation Turbo frame in place. Plan/quota errors render a friendly
 * inline state (not a 500) — the user already paid for the quota or needs an
 * upgrade nudge.
 */
final class ExplainReportController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly DmarcReportRepository $reportRepository,
        private readonly AiInsightsService $aiService,
        private readonly PlanEnforcement $enforcement,
        private readonly PlanLimits $limits,
    ) {
    }

    #[Route('/app/reports/{id}/explain', name: 'dashboard_report_explain', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('explain_report', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $report = $this->reportRepository->findForTeams(Uuid::fromString($id), $this->dashboardContext->getTeamIds());
        if (null === $report) {
            throw $this->createNotFoundException('Report not found.');
        }

        // Scope AI access and quota to the team that OWNS the report — which may
        // differ from the active team for a user who belongs to several teams.
        $team = $report->monitoredDomain->team;
        $plan = $team->getSubscriptionPlan();

        try {
            $result = $this->aiService->explainReport($report->id, $team->id);
        } catch (AiNotEnabledForPlan) {
            return $this->fragment($report, ['upgradePlan' => $plan->withAi()->value]);
        } catch (AiQuotaExceeded $exception) {
            return $this->fragment($report, ['quotaUsed' => $exception->used, 'quotaLimit' => $exception->limit]);
        } catch (ReportNotAnalyzable) {
            return $this->fragment($report, ['unavailable' => true]);
        }

        return $this->fragment($report, [
            'explanation' => $result->explanation,
            'remaining' => $this->enforcement->getRemainingAiQuota($team->id->toString(), $plan),
            'limit' => $this->limits->getOnDemandAiQuota($plan),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fragment(DmarcReport $report, array $params): Response
    {
        return $this->render('dashboard/_report_explanation.html.twig', ['report' => $report] + $params);
    }
}

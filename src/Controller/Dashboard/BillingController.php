<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetBillingOverview;
use App\Query\GetMonthlyReportUsage;
use App\Results\MonthlyReportUsageResult;
use App\Services\DashboardContext;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BillingController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetBillingOverview $getBillingOverview,
        private readonly PlanLimits $planLimits,
        private readonly PlanEnforcement $planEnforcement,
        private readonly GetMonthlyReportUsage $getMonthlyReportUsage,
    ) {
    }

    #[Route('/app/settings/billing', name: 'dashboard_billing', methods: ['GET'])]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $billing = $this->getBillingOverview->forTeam($teamId->toString());

        $aiQuotaUsed = null;
        $aiQuotaLimit = null;
        if ($billing->plan->hasAi()) {
            $aiQuotaUsed = $this->planEnforcement->getOnDemandAiUsage($teamId->toString());
            $aiQuotaLimit = $this->planLimits->getOnDemandAiQuota($billing->plan);
        }

        $reportUsage = null;
        $rawUsage = $this->getMonthlyReportUsage->forTeam($teamId->toString());
        if (null !== $rawUsage) {
            $maxReports = $this->planLimits->getMaxReportsPerMonth($billing->plan);
            $retentionDays = $this->planLimits->getRetentionDays($billing->plan);
            $isUnlimited = PHP_INT_MAX === $maxReports;
            $percentageUsed = $isUnlimited || 0 === $maxReports
                ? 0.0
                : min(100.0, ($rawUsage->currentCount / $maxReports) * 100.0);
            $reportUsage = new MonthlyReportUsageResult(
                currentCount: $rawUsage->currentCount,
                limit: $maxReports,
                percentageUsed: $percentageUsed,
                periodEndsAt: $rawUsage->periodEndsAt,
                planOverageQuarantineCount: $rawUsage->planOverageQuarantineCount,
                isUnlimited: $isUnlimited,
                retentionDays: $retentionDays,
            );
        }

        return $this->render('dashboard/billing.html.twig', [
            'billing' => $billing,
            'maxDomains' => $this->planLimits->getMaxDomains($billing->plan),
            'maxMembers' => $this->planLimits->getMaxTeamMembers($billing->plan),
            'aiQuotaUsed' => $aiQuotaUsed,
            'aiQuotaLimit' => $aiQuotaLimit,
            'reportUsage' => $reportUsage,
        ]);
    }
}

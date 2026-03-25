<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetBillingOverview;
use App\Services\DashboardContext;
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
    ) {
    }

    #[Route('/app/settings/billing', name: 'dashboard_billing', methods: ['GET'])]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $billing = $this->getBillingOverview->forTeam($teamId->toString());

        return $this->render('dashboard/billing.html.twig', [
            'billing' => $billing,
            'maxDomains' => $this->planLimits->getMaxDomains($billing->plan),
            'maxMembers' => $this->planLimits->getMaxTeamMembers($billing->plan),
        ]);
    }
}

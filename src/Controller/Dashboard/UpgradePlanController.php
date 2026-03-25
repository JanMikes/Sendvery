<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\TeamRepository;
use App\Services\DashboardContext;
use App\Services\Stripe\SubscriptionManager;
use App\Value\SubscriptionPlan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UpgradePlanController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly SubscriptionManager $subscriptionManager,
    ) {
    }

    #[Route('/app/settings/billing/upgrade/{plan}', name: 'dashboard_billing_upgrade', methods: ['GET'])]
    public function __invoke(string $plan): Response
    {
        $targetPlan = SubscriptionPlan::tryFrom($plan);

        if (null === $targetPlan || SubscriptionPlan::Free === $targetPlan) {
            $this->addFlash('error', 'Invalid plan selected.');

            return $this->redirectToRoute('dashboard_billing');
        }

        $teamId = $this->dashboardContext->getTeamId();
        $team = $this->teamRepository->get($teamId);

        $checkoutUrl = $this->subscriptionManager->createCheckoutSession($team, $targetPlan);

        return $this->redirect($checkoutUrl);
    }
}

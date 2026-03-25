<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\TeamRepository;
use App\Services\DashboardContext;
use App\Services\Stripe\SubscriptionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ManageSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly SubscriptionManager $subscriptionManager,
    ) {
    }

    #[Route('/app/settings/billing/manage', name: 'dashboard_billing_manage', methods: ['GET'])]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $team = $this->teamRepository->get($teamId);

        if (null === $team->stripeCustomerId) {
            $this->addFlash('error', 'No active subscription to manage.');

            return $this->redirectToRoute('dashboard_billing');
        }

        $portalUrl = $this->subscriptionManager->createCustomerPortalSession($team);

        return $this->redirect($portalUrl);
    }
}

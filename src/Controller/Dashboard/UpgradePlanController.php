<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Exceptions\AiNotYetPurchasable;
use App\Repository\TeamRepository;
use App\Services\DashboardContext;
use App\Services\Stripe\SubscriptionManager;
use App\Value\BillingInterval;
use App\Value\SubscriptionPlan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UpgradePlanController extends AbstractController
{
    /** @var list<SubscriptionPlan> */
    private const PURCHASABLE_PLANS = [
        SubscriptionPlan::Personal,
        SubscriptionPlan::PersonalAi,
        SubscriptionPlan::Pro,
        SubscriptionPlan::ProAi,
        SubscriptionPlan::Business,
        SubscriptionPlan::BusinessAi,
    ];

    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly SubscriptionManager $subscriptionManager,
    ) {
    }

    #[Route('/app/settings/billing/upgrade/{plan}', name: 'dashboard_billing_upgrade', methods: ['GET'])]
    public function __invoke(string $plan, Request $request): Response
    {
        $targetPlan = SubscriptionPlan::tryFrom($plan);
        if (null === $targetPlan || !in_array($targetPlan, self::PURCHASABLE_PLANS, true)) {
            $this->addFlash('error', 'Invalid plan selected.');

            return $this->redirectToRoute('dashboard_billing');
        }

        $interval = BillingInterval::tryFrom($request->query->getString('interval', 'annual')) ?? BillingInterval::Annual;

        $teamId = $this->dashboardContext->getTeamId();
        $team = $this->teamRepository->get($teamId);

        try {
            // Existing subscriber → in-place Stripe update (proration handled
            // by Stripe). New subscriber → fresh Checkout session.
            if (null !== $team->stripeSubscriptionId) {
                $this->subscriptionManager->updateSubscription($team, $targetPlan, $interval);
                $this->addFlash('billing_success', 'Plan change requested — your subscription will update shortly.');

                return $this->redirectToRoute('dashboard_billing');
            }

            $checkoutUrl = $this->subscriptionManager->createCheckoutSession($team, $targetPlan, $interval);
        } catch (AiNotYetPurchasable) {
            // DEC-057: AI plans aren't purchasable yet — route to the AI-curious
            // lead form so we collect interest until the real AI service ships.
            return $this->redirectToRoute('request_beta_access', [
                'plan' => $targetPlan->baseTier()->value,
                'source' => 'dashboard-ai-curious',
            ]);
        }

        return $this->redirect($checkoutUrl);
    }
}

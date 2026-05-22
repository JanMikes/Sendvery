<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Entity\Team;
use App\Message\DowngradeTeamPlan;
use App\Message\UpgradeTeamPlan;
use App\Value\BillingInterval;
use App\Value\SubscriptionPlan;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Webhook;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SubscriptionManager
{
    public function __construct(
        private StripeClient $stripeClient,
        private MessageBusInterface $commandBus,
        private StripePriceResolver $priceResolver,
        private LoggerInterface $logger,
        private string $defaultUri,
    ) {
    }

    public function createCheckoutSession(Team $team, SubscriptionPlan $plan, BillingInterval $interval): string
    {
        $params = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $this->priceResolver->getPriceId($plan, $interval),
                'quantity' => 1,
            ]],
            'success_url' => $this->defaultUri.'/app/settings/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->defaultUri.'/app/settings/billing/cancel',
            'metadata' => [
                'team_id' => $team->id->toString(),
                'plan' => $plan->value,
                'interval' => $interval->value,
            ],
        ];

        if (null !== $team->stripeCustomerId) {
            $params['customer'] = $team->stripeCustomerId;
        } else {
            $params['customer_creation'] = 'always';
        }

        $session = $this->stripeClient->checkout->sessions->create($params);

        return (string) $session->url;
    }

    /**
     * Switch an existing subscription to a new (plan, interval) tuple. Used
     * by in-dashboard plan changes — upgrading/downgrading tier, flipping
     * monthly↔annual cadence, or adding/removing the AI variant.
     *
     * Stripe handles proration via `proration_behavior: create_prorations`
     * — annual-to-monthly mid-cycle yields a credit; monthly-to-annual
     * charges the prorated annual amount immediately. The UI is expected
     * to surface a preview before invoking this method.
     */
    public function updateSubscription(Team $team, SubscriptionPlan $plan, BillingInterval $interval): void
    {
        if (null === $team->stripeSubscriptionId) {
            throw new \RuntimeException('Team has no active Stripe subscription to update.');
        }

        $newPriceId = $this->priceResolver->getPriceId($plan, $interval);
        $subscription = $this->stripeClient->subscriptions->retrieve($team->stripeSubscriptionId);

        // Subscriptions have a single price item under our model (no add-ons
        // before extras land — DEC-056). Swap that item to the new price.
        $itemId = $subscription->items->data[0]->id ?? null;
        if (null === $itemId) {
            throw new \RuntimeException('Stripe subscription has no items to update.');
        }

        $this->stripeClient->subscriptions->update($team->stripeSubscriptionId, [
            'items' => [[
                'id' => $itemId,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'create_prorations',
            'metadata' => [
                'team_id' => $team->id->toString(),
                'plan' => $plan->value,
                'interval' => $interval->value,
            ],
        ]);
    }

    public function createCustomerPortalSession(Team $team): string
    {
        if (null === $team->stripeCustomerId) {
            throw new \RuntimeException('Team has no Stripe customer ID.');
        }

        $session = $this->stripeClient->billingPortal->sessions->create([
            'customer' => $team->stripeCustomerId,
            'return_url' => $this->defaultUri.'/app/settings/billing',
        ]);

        return (string) $session->url;
    }

    public function handleWebhook(string $payload, string $signature, string $webhookSecret): void
    {
        $event = Webhook::constructEvent($payload, $signature, $webhookSecret);

        /** @var StripeObject $object */
        $object = $event->data->object;
        $data = $object->toArray();

        $this->dispatchStripeEvent((string) $event->type, $data);
    }

    /**
     * Event-routing surface called from `handleWebhook` after signature
     * verification. Public for testability — production callers should
     * always go through `handleWebhook` so the signature check runs.
     *
     * @param array<string, mixed> $data
     */
    public function dispatchStripeEvent(string $eventType, array $data): void
    {
        \Sentry\addBreadcrumb(\Sentry\Breadcrumb::fromArray([
            'category' => 'stripe.webhook',
            'level' => 'info',
            'message' => $eventType,
            'data' => [
                'team_id' => $data['metadata']['team_id'] ?? null,
                'plan' => $data['metadata']['plan'] ?? null,
                'interval' => $data['metadata']['interval'] ?? null,
            ],
        ]));

        match ($eventType) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($data),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($data),
            // trial_will_end + others: log-only at launch, no action needed.
            default => $this->logger->info('Ignoring Stripe event {type}', ['type' => $eventType]),
        };
    }

    /** @param array<string, mixed> $session */
    private function handleCheckoutCompleted(array $session): void
    {
        /** @var array<string, string>|null $metadata */
        $metadata = $session['metadata'] ?? null;
        $teamId = $metadata['team_id'] ?? null;
        $planValue = $metadata['plan'] ?? null;
        $intervalValue = $metadata['interval'] ?? null;

        if (!\is_string($teamId) || !\is_string($planValue)) {
            return;
        }

        $plan = SubscriptionPlan::tryFrom($planValue);
        if (null === $plan) {
            return;
        }

        $interval = \is_string($intervalValue) ? BillingInterval::tryFrom($intervalValue) : null;

        $this->commandBus->dispatch(new UpgradeTeamPlan(
            teamId: Uuid::fromString($teamId),
            plan: $plan,
            stripeSubscriptionId: (string) ($session['subscription'] ?? ''),
            stripeCustomerId: (string) ($session['customer'] ?? ''),
            billingInterval: $interval,
        ));
    }

    /**
     * `customer.subscription.updated` fires whenever a subscription's
     * price, status, or metadata changes — including in-place plan/cadence/
     * AI swaps initiated via `updateSubscription()`. Pull the new plan
     * out of metadata (which `updateSubscription` populates) and dispatch
     * an `UpgradeTeamPlan` command so the local Team state catches up.
     *
     * @param array<string, mixed> $subscription
     */
    private function handleSubscriptionUpdated(array $subscription): void
    {
        /** @var array<string, string>|null $metadata */
        $metadata = $subscription['metadata'] ?? null;
        $teamId = $metadata['team_id'] ?? null;
        $planValue = $metadata['plan'] ?? null;
        $intervalValue = $metadata['interval'] ?? null;
        $subscriptionId = $subscription['id'] ?? null;
        $customerId = $subscription['customer'] ?? null;

        if (!\is_string($teamId) || !\is_string($planValue) || !\is_string($subscriptionId)) {
            return;
        }

        $plan = SubscriptionPlan::tryFrom($planValue);
        if (null === $plan) {
            return;
        }

        $interval = \is_string($intervalValue) ? BillingInterval::tryFrom($intervalValue) : null;

        $this->commandBus->dispatch(new UpgradeTeamPlan(
            teamId: Uuid::fromString($teamId),
            plan: $plan,
            stripeSubscriptionId: $subscriptionId,
            stripeCustomerId: \is_string($customerId) ? $customerId : '',
            billingInterval: $interval,
        ));
    }

    /**
     * `invoice.payment_failed` fires when Stripe's automatic retry attempts
     * fail. Log-only at launch — Stripe's built-in retry schedule + the
     * subsequent `customer.subscription.deleted` event take care of the
     * actual downgrade. Phase 7 will add Sentry breadcrumbs + grace-period
     * UX on top of this.
     *
     * @param array<string, mixed> $invoice
     */
    private function handleInvoicePaymentFailed(array $invoice): void
    {
        $this->logger->warning('Stripe invoice payment failed', [
            'invoice_id' => $invoice['id'] ?? null,
            'customer' => $invoice['customer'] ?? null,
            'subscription' => $invoice['subscription'] ?? null,
            'attempt_count' => $invoice['attempt_count'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $subscription */
    private function handleSubscriptionDeleted(array $subscription): void
    {
        /** @var array<string, string>|null $metadata */
        $metadata = $subscription['metadata'] ?? null;
        $teamId = $metadata['team_id'] ?? null;

        if (!\is_string($teamId)) {
            return;
        }

        $this->commandBus->dispatch(new DowngradeTeamPlan(
            teamId: Uuid::fromString($teamId),
        ));
    }
}

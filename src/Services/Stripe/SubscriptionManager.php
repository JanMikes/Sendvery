<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Entity\Team;
use App\Message\DowngradeTeamPlan;
use App\Message\UpgradeTeamPlan;
use App\Value\SubscriptionPlan;
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
        private string $defaultUri,
    ) {
    }

    public function createCheckoutSession(Team $team, SubscriptionPlan $plan): string
    {
        $params = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $this->priceResolver->getPriceId($plan),
                'quantity' => 1,
            ]],
            'success_url' => $this->defaultUri.'/app/settings/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->defaultUri.'/app/settings/billing/cancel',
            'metadata' => [
                'team_id' => $team->id->toString(),
                'plan' => $plan->value,
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

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),
            default => null,
        };
    }

    /** @param array<string, mixed> $session */
    private function handleCheckoutCompleted(array $session): void
    {
        /** @var array<string, string>|null $metadata */
        $metadata = $session['metadata'] ?? null;
        $teamId = $metadata['team_id'] ?? null;
        $planValue = $metadata['plan'] ?? null;

        if (!\is_string($teamId) || !\is_string($planValue)) {
            return;
        }

        $plan = SubscriptionPlan::tryFrom($planValue);
        if (null === $plan) {
            return;
        }

        $this->commandBus->dispatch(new UpgradeTeamPlan(
            teamId: Uuid::fromString($teamId),
            plan: $plan,
            stripeSubscriptionId: (string) ($session['subscription'] ?? ''),
            stripeCustomerId: (string) ($session['customer'] ?? ''),
        ));
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

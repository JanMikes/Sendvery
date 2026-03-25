<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Services\Stripe\SubscriptionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionManager $subscriptionManager,
        private readonly string $stripeWebhookSecret,
    ) {
    }

    #[Route('/webhook/stripe', name: 'webhook_stripe', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature', '');

        if ('' === $signature) {
            return new Response('Missing Stripe-Signature header', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->subscriptionManager->handleWebhook($payload, $signature, $this->stripeWebhookSecret);
        } catch (\Stripe\Exception\SignatureVerificationException) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        return new Response('OK', Response::HTTP_OK);
    }
}

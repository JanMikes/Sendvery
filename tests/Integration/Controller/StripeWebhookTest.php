<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StripeWebhookTest extends WebTestCase
{
    #[Test]
    public function webhookRejectsMissingSignature(): void
    {
        $client = self::createClient();

        $client->request('POST', '/webhook/stripe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function webhookRejectsInvalidSignature(): void
    {
        $client = self::createClient();

        $client->request('POST', '/webhook/stripe', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1234567890,v1=invalid_signature',
        ], '{"type": "checkout.session.completed"}');

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function webhookEndpointIsPublic(): void
    {
        $client = self::createClient();

        // Even without auth, the endpoint should be accessible (not redirect to login)
        $client->request('POST', '/webhook/stripe', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1234567890,v1=test',
        ], '{}');

        // Should get 400 (bad signature), not 302 (redirect to login)
        self::assertResponseStatusCodeSame(400);
    }
}

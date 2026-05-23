<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TrustPagesTest extends WebTestCase
{
    public function testPrivacyPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/privacy');

        self::assertResponseIsSuccessful();
    }

    public function testSecurityPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/security');

        self::assertResponseIsSuccessful();
    }

    public function testStatusPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/status');

        self::assertResponseIsSuccessful();
    }

    public function testPrivacyPageContainsH1(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/privacy');

        self::assertSelectorTextContains('h1', 'Privacy Policy');
    }

    public function testSecurityPageContainsH1(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/security');

        self::assertSelectorTextContains('h1', 'Security Overview');
    }

    public function testStatusPageContainsH1(): void
    {
        $client = self::createClient();
        $client->request('GET', '/status');

        self::assertSelectorTextContains('h1', 'Sendvery System Status');
    }

    public function testPrivacyPageContainsLastUpdated(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/privacy');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('2026-05-23', $body);
    }

    public function testSecurityPageContainsLastUpdated(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/security');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('2026-05-23', $body);
    }

    public function testPrivacyPageContainsSubProcessors(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/privacy');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Stripe', $body);
        self::assertStringContainsString('Anthropic', $body);
        self::assertStringContainsString('Sentry', $body);
        self::assertStringContainsString('Hetzner', $body);
    }

    public function testPrivacyPageContainsGdprRights(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/privacy');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Right to access', $body);
        self::assertStringContainsString('privacy@sendvery.com', $body);
    }

    public function testSecurityPageContainsMagicLinkClaim(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/security');

        $body = strtolower((string) $client->getResponse()->getContent());
        self::assertStringContainsString('magic-link', $body);
        self::assertStringContainsString('never store passwords', $body);
    }

    public function testSecurityPageContainsEncryptionClaim(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/security');

        // Regression guard against re-introducing the false AES-256-GCM claim.
        // The truthful claim must mention at least one of: halite, libsodium, XChaCha20.
        $body = strtolower((string) $client->getResponse()->getContent());
        $mentionsTruthfulPrimitive = str_contains($body, 'halite')
            || str_contains($body, 'libsodium')
            || str_contains($body, 'xchacha20');

        self::assertTrue(
            $mentionsTruthfulPrimitive,
            'Security page must describe encryption using the actual primitive (halite / libsodium / XChaCha20), not AES-256-GCM.',
        );
    }

    public function testSecurityPageContainsResponsibleDisclosure(): void
    {
        $client = self::createClient();
        $client->request('GET', '/legal/security');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('security@sendvery.com', $body);
    }

    public function testStatusPageContainsOperationalStatus(): void
    {
        $client = self::createClient();
        $client->request('GET', '/status');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('All systems operational', $body);
    }

    public function testStatusPageContainsWebApplicationComponent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/status');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Web application', $body);
    }

    public function testFooterContainsTrustLinks(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $footer = $crawler->filter('footer');
        $footerText = $footer->text();

        self::assertStringContainsString('Privacy', $footerText);
        self::assertStringContainsString('Security', $footerText);
        self::assertStringContainsString('Status', $footerText);
    }
}

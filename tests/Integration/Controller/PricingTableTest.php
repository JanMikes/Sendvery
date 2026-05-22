<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PricingTableTest extends WebTestCase
{
    public function testRendersAllFourCardsOnPricingPage(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();

        $cards = $crawler->filter('[data-pricing-target="card"]');
        self::assertCount(4, $cards, 'Expected one card per tier (Free / Personal / Pro / Business).');

        $titles = $cards->each(static fn ($card) => trim($card->filter('h3')->text()));
        self::assertSame(['Free', 'Personal', 'Pro', 'Business'], $titles);
    }

    public function testFreeCardMarksItselfAsNotAiAvailable(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $freeCard = $crawler->filter('[data-pricing-target="card"]')->first();
        self::assertSame('false', $freeCard->attr('data-ai-available'));
    }

    public function testPaidCardsCarryAiAndCadencePriceData(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $personal = $crawler->filter('[data-base-plan="personal"]');
        self::assertCount(1, $personal);
        self::assertSame('$5.99', $personal->attr('data-price-monthly'));
        self::assertSame('$4.99', $personal->attr('data-price-annual'));
        self::assertSame('$9.99', $personal->attr('data-price-ai-monthly'));
        self::assertSame('$8.99', $personal->attr('data-price-ai-annual'));
        self::assertSame('Save $12/yr', $personal->attr('data-chip-annual'));

        $pro = $crawler->filter('[data-base-plan="pro"]');
        self::assertSame('$23.99', $pro->attr('data-price-monthly'));
        self::assertSame('$19.99', $pro->attr('data-price-annual'));
        self::assertSame('$33.99', $pro->attr('data-price-ai-monthly'));
        self::assertSame('$29.99', $pro->attr('data-price-ai-annual'));
        self::assertSame('Save $48/yr', $pro->attr('data-chip-annual'));

        $business = $crawler->filter('[data-base-plan="business"]');
        self::assertSame('$59.99', $business->attr('data-price-monthly'));
        self::assertSame('$49.99', $business->attr('data-price-annual'));
        self::assertSame('$79.99', $business->attr('data-price-ai-monthly'));
        self::assertSame('$69.99', $business->attr('data-price-ai-annual'));
        self::assertSame('Save $120/yr', $business->attr('data-chip-annual'));
    }

    public function testAnnualIsTheDefaultServerRenderedState(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $container = $crawler->filter('[data-controller="pricing"]');
        self::assertSame('annual', $container->attr('data-pricing-billing-value'));
        self::assertSame('false', $container->attr('data-pricing-ai-value'));

        // Server-rendered prices reflect the annual default.
        $personal = $crawler->filter('[data-base-plan="personal"] [data-pricing-target="price"]');
        self::assertSame('$4.99', $personal->text());
    }

    public function testEnterpriseLineRendersBelowGrid(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        self::assertSelectorTextContains('body', 'Talk to us about Enterprise');
    }

    public function testHasBillingAndAiToggles(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        self::assertCount(2, $crawler->filter('[data-pricing-target="billingButton"]'));
        self::assertCount(1, $crawler->filter('[data-pricing-target="aiToggle"]'));
    }

    public function testFreeTierCtaPointsToAuthLogin(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $freeCard = $crawler->filter('[data-pricing-target="card"]')->first();
        $cta = $freeCard->filter('a.btn');
        self::assertCount(1, $cta);
        self::assertSame('/login', $cta->attr('href'));
        self::assertStringContainsString('Get started free', $cta->text());
    }

    public function testNoBetaHrefOnPricingPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('href="/beta"', $body);
    }
}

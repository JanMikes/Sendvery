<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PricingPageTest extends WebTestCase
{
    public function testPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
    }

    public function testComparisonTableDesktopVariantIsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        self::assertGreaterThanOrEqual(1, $crawler->filter('table.table.table-zebra')->count());
    }

    public function testComparisonTableMobileVariantIsPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        // The mobile variant wraps four per-tier cards in a `md:hidden` container.
        self::assertStringContainsString('md:hidden', $body);
    }

    public function testComparisonTableContainsAllTierNames(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        // Each tier name appears once in PricingTable (h3) + once each in desktop table header
        // and mobile card, so at minimum twice across the page.
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Free<'));
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Personal<'));
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Pro<'));
        self::assertGreaterThanOrEqual(2, substr_count($body, '>Business<'));
    }

    public function testComparisonTableContainsAllFeatureRows(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Domains', $body);
        self::assertStringContainsString('Reports / month', $body);
        self::assertStringContainsString('Report retention', $body);
        self::assertStringContainsString('Team members', $body);
        self::assertStringContainsString('DMARC + DNS monitoring', $body);
        self::assertStringContainsString('Real-time alerts', $body);
        self::assertStringContainsString('Blacklist monitoring', $body);
        self::assertStringContainsString('Sender inventory', $body);
        self::assertStringContainsString('Email HTML reports', $body);
        self::assertStringContainsString('API access + webhooks', $body);
        self::assertStringContainsString('White-label PDF reports', $body);
        self::assertStringContainsString('AI Insights', $body);
        self::assertStringContainsString('Support', $body);
    }

    public function testFaqSectionIsPresent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        // FAQ uses .collapse-plus (checkbox-driven, distinct from FaqAccordion's radio variant).
        self::assertGreaterThanOrEqual(8, $crawler->filter('.collapse-plus')->count());
    }

    public function testFaqContainsAllExpectedQuestions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Can I cancel anytime?', $body);
        self::assertStringContainsString('Do you offer refunds?', $body);
        self::assertStringContainsString('Can I switch plans later?', $body);
        self::assertStringContainsString('What happens if I exceed my plan limits?', $body);
        self::assertStringContainsString('Do you offer annual discounts?', $body);
        self::assertStringContainsString('Is there a free trial?', $body);
        self::assertStringContainsString('What payment methods do you accept?', $body);
        self::assertStringContainsString('Do you charge VAT?', $body);
        self::assertStringContainsString('Why is self-hosting free?', $body);
        self::assertStringContainsString('How does AI Insights work?', $body);
    }

    public function testStartFreeCtaPointsToAuthLogin(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $loginLinks = $crawler->filter('a[href="/login"]');
        self::assertGreaterThanOrEqual(1, $loginLinks->count());

        // The final CTA section has a "Start free" button — confirm the literal label is present.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Start free', $body);
    }

    public function testTalkToUsCtaIsMailtoLink(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $mailtoLinks = $crawler->filter('a[href^="mailto:jan.mikes@sendvery.com"]');
        self::assertGreaterThanOrEqual(1, $mailtoLinks->count());
    }

    public function testMetaDescriptionContainsAnnualSavings(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/pricing');

        $metaDescription = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('$12', $metaDescription);
        self::assertStringContainsString('$48', $metaDescription);
        self::assertStringContainsString('$120', $metaDescription);
    }

    public function testWhyAnnualCalloutIsPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pricing');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Annual billing', $body);
        self::assertStringContainsString('2 months free', $body);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ToolPagesTest extends WebTestCase
{
    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function toolPageHasInteractiveChecker(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        // Checker widgets are Live Components (no <form>, no submit button) —
        // see CheckerComponentsTest for the rationale. Look for the LiveProp-bound
        // domain input and the click-triggered action button.
        self::assertSelectorExists('input[data-model*="domain"]');
        self::assertSelectorExists('button[data-live-action-param="check"]');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function toolPageHasFaqSection(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Frequently asked questions');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function toolPageHasFaqStructuredData(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        $jsonLdElements = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThanOrEqual(1, $jsonLdElements->count());

        $hasFaqSchema = false;
        $jsonLdElements->each(function ($node) use (&$hasFaqSchema): void {
            $data = json_decode($node->text(), true);
            if (($data['@type'] ?? '') === 'FAQPage') {
                $hasFaqSchema = true;
            }
        });

        self::assertTrue($hasFaqSchema, 'Page should have FAQPage structured data');
    }

    #[Test]
    #[DataProvider('toolPagesWithCheckers')]
    public function toolPageHasBreadcrumbStructuredData(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        $jsonLdElements = $crawler->filter('script[type="application/ld+json"]');

        $hasBreadcrumbs = false;
        $jsonLdElements->each(function ($node) use (&$hasBreadcrumbs): void {
            $data = json_decode($node->text(), true);
            if (($data['@type'] ?? '') === 'BreadcrumbList') {
                $hasBreadcrumbs = true;
            }
        });

        self::assertTrue($hasBreadcrumbs, 'Page should have BreadcrumbList structured data');
    }

    /**
     * TASK-140 — the per-page "Related tools" chip grid was retired (it
     * duplicated the footer's Free Tools column + the nav Tools dropdown).
     * Pin its absence on every tool page so a careless restore would have
     * to retire this test explicitly.
     */
    #[Test]
    #[DataProvider('allToolPages')]
    public function toolPageDoesNotShowRelatedToolsChipGrid(string $url): void
    {
        $client = self::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // Case-insensitive guard: a future contributor might re-introduce the
        // block under "Related Tools" (title case) — catch either spelling.
        self::assertStringNotContainsStringIgnoringCase('Related tools', $body, sprintf('Tool page %s must not render the retired "Related tools" chip grid (TASK-140).', $url));
    }

    #[Test]
    #[DataProvider('allToolPages')]
    public function toolPageHasSeoContent(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $title = $crawler->filter('title')->text();
        self::assertNotEmpty($title);
        self::assertStringContainsString('Sendvery', $title);

        $metaDescription = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotEmpty($metaDescription);
        self::assertGreaterThan(50, strlen($metaDescription), 'Meta description should be substantial');
    }

    #[Test]
    public function spfCheckerHasCorrectH1(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/spf-checker');

        self::assertSelectorTextContains('h1', 'SPF Record Checker');
    }

    #[Test]
    public function dkimCheckerHasSelectorInput(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/dkim-checker');

        self::assertSelectorExists('input[data-model*="selector"]');
    }

    #[Test]
    public function domainHealthHasCorrectH1(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/domain-health');

        self::assertSelectorTextContains('h1', 'Domain Health Check');
    }

    #[Test]
    public function blacklistCheckerShowsComingSoon(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/blacklist-checker');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Blacklist Checker');
    }

    #[Test]
    public function dnsMonitoringHasAuthLoginCta(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/tools/dns-monitoring');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DNS Record Monitoring');
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/login"]')->count(), 'DNS monitoring page should link to /login');
    }

    /**
     * TASK-144 — the 4 generator cards (SPF/DMARC/DKIM/MX) live on the
     * existing checker tool pages as the first SEO section, wired via
     * Stimulus controllers. The asserts below pin the controller name
     * + the JSON data attributes so a future refactor that drops the
     * generator surface has to touch the test explicitly.
     */
    #[Test]
    public function spfGeneratorRendersControllerAttribute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/spf-checker');

        self::assertSelectorExists('[data-controller="spf-generator"]');
    }

    #[Test]
    public function spfGeneratorHasGoogleWorkspaceProvider(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/spf-checker');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Google Workspace', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function spfGeneratorDataAttributeContainsProvidersJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/spf-checker');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-spf-generator-providers-value', $body);
        self::assertStringContainsString('_spf.google.com', $body);
    }

    #[Test]
    public function dmarcGeneratorRendersControllerAttribute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/dmarc-checker');

        self::assertSelectorExists('[data-controller="dmarc-generator"]');
    }

    #[Test]
    public function dmarcGeneratorHasPolicies(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/dmarc-checker');

        // TASK-156: guard the status code so a 500 surfaces as a status-code
        // assertion failure rather than the less-actionable "string not in body".
        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('value="none"', $body);
        self::assertStringContainsString('value="quarantine"', $body);
        self::assertStringContainsString('value="reject"', $body);
    }

    #[Test]
    public function dkimGeneratorRendersControllerAttribute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/dkim-checker');

        self::assertSelectorExists('[data-controller="dkim-generator"]');
    }

    #[Test]
    public function mxGeneratorRendersControllerAttribute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/mx-checker');

        self::assertSelectorExists('[data-controller="mx-generator"]');
    }

    #[Test]
    public function mxGeneratorHasGoogleWorkspacePreset(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/mx-checker');

        // TASK-156: status-code guard so a 500 surfaces clearly.
        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Google Workspace', $body);
    }

    #[Test]
    public function mxGeneratorDataAttributeContainsPresetsJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/mx-checker');

        // TASK-156: status-code guard so a 500 surfaces clearly.
        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-mx-generator-presets-value', $body);
        self::assertStringContainsString('ASPMX.L.GOOGLE.COM', $body);
    }

    /** @return iterable<string, array{string}> */
    public static function toolPagesWithCheckers(): iterable
    {
        yield 'spf-checker' => ['/tools/spf-checker'];
        yield 'dkim-checker' => ['/tools/dkim-checker'];
        yield 'dmarc-checker' => ['/tools/dmarc-checker'];
        yield 'email-auth-checker' => ['/tools/email-auth-checker'];
        yield 'mx-checker' => ['/tools/mx-checker'];
        yield 'domain-health' => ['/tools/domain-health'];
    }

    /** @return iterable<string, array{string}> */
    public static function allToolPages(): iterable
    {
        yield from self::toolPagesWithCheckers();
        yield 'blacklist-checker' => ['/tools/blacklist-checker'];
        yield 'dns-monitoring' => ['/tools/dns-monitoring'];
    }
}

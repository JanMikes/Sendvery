<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DomainHealthSnapshot;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\RouterInterface;

/**
 * Covers the new in-app DNS Health overview route — replaces the old
 * sidebar shortcut to the public /tools/domain-health lookup tool.
 */
final class DnsHealthOverviewTest extends WebTestCase
{
    #[Test]
    public function pageReturns200ForAuthenticatedUserWithDomain(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function pageShowsDomainName(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($persona->domain->domain, (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function pageShowsEmptyStateWhenNoDomains(): void
    {
        // The OnboardingRedirectListener intercepts every /app/* request from
        // a user with zero monitored domains and redirects to onboarding, so
        // a "no domains" user can never actually land on /app/dns-health
        // through normal navigation — same as `dashboard_domains`
        // (DashboardPagesTest::userWithNoDomainsIsRedirectedToOnboarding).
        // Assert the redirect, then verify the template's empty-state branch
        // by static template inspection + URL generation so the copy and
        // add-domain CTA are still covered.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/dns-health');

        self::assertResponseRedirects('/app/onboarding/team');

        $router = $client->getContainer()->get('router');
        assert($router instanceof RouterInterface);
        $template = (string) file_get_contents(__DIR__.'/../../../templates/dashboard/dns_health_overview.html.twig');
        self::assertStringContainsString('No domains yet', $template);
        self::assertStringContainsString("path('dashboard_domain_add')", $template);
        self::assertSame('/app/domains/add', $router->generate('dashboard_domain_add'));
    }

    #[Test]
    public function pageShowsMultipleDomains(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $extra = $fixtures->addExtraDomain($persona->team, 'second-domain');

        $client->loginUser($persona->user);
        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($persona->domain->domain, $body);
        self::assertStringContainsString($extra->domain, $body);
    }

    #[Test]
    public function pageShowsGradeWhenSnapshotExists(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 100,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('text-success', $body);
        // The grade letter appears in a font-bold badge next to the domain name.
        self::assertMatchesRegularExpression('/badge[^"]*text-success[^"]*font-bold[^>]*>\s*A\s*</', $body);
    }

    #[Test]
    public function pageShowsNoDataFallbackWithoutSnapshot(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No health data yet', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function redirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/app/dns-health');

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function sidebarHighlightsDnsHealthWhenActive(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // The active sidebar item has the bg-primary/text-primary-content
        // pair inline; assert that pair shows up adjacent to the DNS Health
        // label so we know the highlight is wired to this route.
        self::assertMatchesRegularExpression(
            '/bg-primary text-primary-content[^<]*<\/a>\s*<a[^>]*>[^<]*<svg[\s\S]*?DNS Health|bg-primary text-primary-content[\s\S]{0,1200}DNS Health/',
            $body,
        );
    }

    #[Test]
    public function noDashboardLinksToPublicDomainHealthTool(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/dns-health');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/tools/domain-health', (string) $client->getResponse()->getContent());
    }
}

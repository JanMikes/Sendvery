<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DomainHealthSnapshot;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * TASK-130: /app/domains absorbs the deleted /app/dns-health surface — the
 * 4-card summary, the per-card grade chip + protocol badges, the DNS Health
 * footer link, and the new ?status=unchecked filter chip all live here.
 *
 * Coverage:
 *  - 4-card stat summary renders at the top of the page
 *  - per-card grade chip is present when a snapshot exists, absent otherwise
 *  - per-card SPF/DKIM/DMARC/MX protocol badges render as styled spans
 *  - per-card "DNS Health →" link deep-links to the per-domain drill-down
 *  - ?status=unchecked filter narrows the list to domains without a snapshot
 *  - no surviving link to the public /tools/domain-health from the dashboard
 *  - badges are non-interactive spans (NOT nested anchors) — HTML validity
 */
final class DomainsWithDnsHealthTest extends WebTestCase
{
    #[Test]
    public function fourCardSummaryRendersAtTopOfDomainsPage(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Domains monitored', $body);
        self::assertStringContainsString('Fully healthy', $body);
        self::assertStringContainsString('Need attention', $body);
        self::assertStringContainsString('Awaiting first check', $body);
    }

    #[Test]
    public function cardShowsGradeChipWhenSnapshotExists(): void
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
        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // The grade letter is rendered inside a font-bold badge span on the card.
        self::assertMatchesRegularExpression(
            '/<span[^>]*class="badge[^"]*text-success[^"]*font-bold[^>]*>\s*A\s*</',
            $body,
        );
    }

    #[Test]
    public function cardOmitsGradeChipWhenNoSnapshot(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // No grade span renders when the domain has no health snapshot. We
        // look for the aria-label that uniquely identifies the grade chip; if
        // it's absent, the chip wasn't rendered.
        self::assertStringNotContainsString(
            sprintf('aria-label="DNS health grade A for %s"', $persona->domain->domain),
            $body,
        );
        self::assertStringNotContainsString(
            sprintf('aria-label="DNS health grade B for %s"', $persona->domain->domain),
            $body,
        );
    }

    #[Test]
    public function cardRendersFourProtocolBadges(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona->domain->spfVerifiedAt = new \DateTimeImmutable('-1 day');
        $persona->domain->dkimVerifiedAt = new \DateTimeImmutable('-1 day');
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-1 day');
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
        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // All four protocol labels render. The badges are spans (not anchors),
        // each carrying the canonical aria-label for state.
        self::assertStringContainsString('>SPF<', $body);
        self::assertStringContainsString('>DKIM<', $body);
        self::assertStringContainsString('>DMARC<', $body);
        self::assertStringContainsString('>MX<', $body);
    }

    #[Test]
    public function cardHasDnsHealthFooterLinkToPerDomainDrillDown(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $expectedHref = sprintf('/app/domains/%s/health', $persona->domain->id);

        // Single deep-link from the badge area to the per-domain DNS drill-down.
        // Anchor carries `relative z-10` so the stretched-link card wrap does
        // not eat its click.
        self::assertMatchesRegularExpression(
            '/<a href="'.preg_quote($expectedHref, '/').'"[^>]*class="[^"]*btn[^"]*relative z-10/',
            $body,
        );
    }

    #[Test]
    public function statusUncheckedFilterShowsOnlyDomainsWithoutSnapshot(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // The persona's default domain has NO snapshot — it should appear under
        // ?status=unchecked. We add a second domain WITH a snapshot to prove
        // the filter actually narrows the list (i.e. the snapshot-having
        // domain does NOT render under ?status=unchecked).
        $checkedDomain = $fixtures->addExtraDomain($persona->team, 'with-snapshot');
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $checkedDomain,
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
        $client->request('GET', '/app/domains?status=unchecked');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($persona->domain->domain, $body);
        self::assertStringNotContainsString($checkedDomain->domain, $body);
    }

    #[Test]
    public function noLinkFromDashboardToPublicDomainHealthTool(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        // The merged page must not link to the public DMARC checker tool —
        // the dashboard owns its own DNS drill-down per /app/domains/{id}/health.
        self::assertStringNotContainsString('/tools/domain-health', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function noTemplateReferencesDashboardDnsHealthRoute(): void
    {
        // TASK-130 codified guard: no template under templates/ may call
        // `path('dashboard_dns_health')` — the route is deleted and any
        // surviving reference would throw a Symfony routing exception at
        // render time. Sweeping every twig file gives us a single regression
        // net for the whole template tree.
        $templatesDir = __DIR__.'/../../../templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir));
        $offenders = [];
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if (!$file->isFile()) {
                continue;
            }
            if ('twig' !== $file->getExtension()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'dashboard_dns_health')) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Templates may not reference the dashboard_dns_health route — it was removed in TASK-130.',
        );
    }

    #[Test]
    public function noControllerOrServiceReferencesDashboardDnsHealthRoute(): void
    {
        // Mirror of the template guard for src/. Catches the case where a
        // future controller or service tries to `generateUrl('dashboard_dns_health')`
        // or pass it as a route name — both would 500 at runtime.
        $srcDir = __DIR__.'/../../../src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        $offenders = [];
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if (!$file->isFile()) {
                continue;
            }
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'dashboard_dns_health')) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Controllers/services may not reference the dashboard_dns_health route — it was removed in TASK-130.',
        );
    }
}

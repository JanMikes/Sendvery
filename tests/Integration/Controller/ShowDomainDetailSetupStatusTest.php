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
 * Covers the bundled TASK-067 + TASK-080 surface on /app/domains/{id}: the
 * one-line status banner up top and the per-protocol setup checklist
 * directly under DomainWorkspaceTabs. Also guards the regression that the
 * old bare SPF/DKIM/DMARC/MX badge chips are gone.
 */
final class ShowDomainDetailSetupStatusTest extends WebTestCase
{
    #[Test]
    public function allGreenDomainShowsHealthyBannerAndAllGreenCardAndNoLegacyBadges(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        $persona->domain->spfVerifiedAt = $verifiedAt;
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Banner — Healthy headline + success bar.
        self::assertStringContainsString('Monitoring active — all four records are in place', $body);
        self::assertStringContainsString('data-testid="domain-status-banner"', $body);

        // TASK-097: all-green hides the panel entirely — the redundant
        // "DNS setup is complete" card would just repeat the banner.
        self::assertStringNotContainsString('data-testid="domain-setup-status-all-green"', $body);
        self::assertStringNotContainsString('DNS setup is complete', $body);

        // Regression guard: the legacy bare badge cluster is gone. The
        // pre-refactor markup rendered the literal `badge-ghost badge-sm">SPF`
        // (and matching DKIM/DMARC/MX); a fully-green domain rendered the
        // success variant. Either fragment proves a regression.
        self::assertStringNotContainsString('badge badge-ghost badge-sm">SPF<', $body);
        self::assertStringNotContainsString('badge badge-sm badge-success">SPF<', $body);
        self::assertStringNotContainsString('badge badge-sm badge-success">DKIM<', $body);
        self::assertStringNotContainsString('badge badge-sm badge-success">DMARC<', $body);
    }

    #[Test]
    public function spfFailingShowsAttentionBannerAndChecklistWithSpfFixLink(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        // SPF intentionally NOT verified — DMARC + DKIM verified. The snapshot
        // carries a low SPF score so the resolver classifies SPF as Invalid
        // (present but failing) rather than the Missing edge that can only
        // occur with no snapshot at all.
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'B',
            score: 75,
            spfScore: 30,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Banner — Attention headline mentions SPF + warning tone.
        self::assertStringContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringContainsString('Action needed', $body);
        self::assertStringContainsString('SPF', $body);

        // Checklist — partial-state branch with the SPF row in Missing state
        // and a Fix-this link pointing at the SPF anchor on the health page.
        self::assertStringContainsString('data-testid="domain-setup-status-checklist"', $body);
        self::assertStringContainsString('of 4 checks passing', $body);
        self::assertMatchesRegularExpression('~href="/app/domains/[^"]+/health\#health-spf"~', $body);
    }

    #[Test]
    public function noDnsHealthYetShowsPendingCardWithReverifyForm(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        // Use an extra domain — defaults to no verifications and no snapshot,
        // so GetDnsHealthOverview::forDomain() returns null.
        $extra = $fixtures->addExtraDomain($persona->team, 'pending-extra');

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $extra->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // TASK-097: banner hides in the unchecked-DNS pending state — the
        // old "DNS not configured yet" headline was a wrong-information bug
        // (we hadn't actually checked yet) and the info-blue panel below
        // leads alone.
        self::assertStringNotContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringNotContainsString('DNS not configured yet — start with the SPF record', $body);

        // Pending card branch — re-check form posting to dashboard_domain_reverify.
        self::assertStringContainsString('data-testid="domain-setup-status-pending"', $body);
        self::assertStringContainsString("We haven't checked DNS yet", $body);
        self::assertMatchesRegularExpression(
            '~<form[^>]*action="/app/domains/[^"]+/reverify"~',
            $body,
        );
    }

    #[Test]
    public function allGreenStateRendersBannerWithoutAllGreenPanel(): void
    {
        // TASK-097: in the all-green state the panel hides entirely — the
        // one-line "Monitoring active" banner is enough, and rendering the
        // "DNS setup is complete" panel below it would just repeat the
        // same news a second time. Guards against re-introducing the
        // duplicate-headline regression.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        $persona->domain->spfVerifiedAt = $verifiedAt;
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Banner renders (the only card for this state).
        self::assertStringContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringContainsString('Monitoring active — all four records are in place', $body);

        // Panel — all three branches must be absent.
        self::assertStringNotContainsString('data-testid="domain-setup-status-all-green"', $body);
        self::assertStringNotContainsString('data-testid="domain-setup-status-checklist"', $body);
        self::assertStringNotContainsString('data-testid="domain-setup-status-pending"', $body);
        // No second "DNS setup is complete" duplicate headline.
        self::assertStringNotContainsString('DNS setup is complete', $body);
    }

    #[Test]
    public function partialSetupRendersBothBannerAndChecklistWithTightSpacing(): void
    {
        // TASK-097: in the partial-setup state both cards render together,
        // with the banner's bottom margin tightened (mb-2) so they read as
        // a single TL;DR → drill-down unit instead of two stacked cards.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $verifiedAt = new \DateTimeImmutable();
        // DMARC + DKIM verified, SPF intentionally NOT verified.
        $persona->domain->dkimVerifiedAt = $verifiedAt;
        $persona->domain->dmarcVerifiedAt = $verifiedAt;

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'B',
            score: 75,
            spfScore: 30,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Both cards render.
        self::assertStringContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringContainsString('data-testid="domain-setup-status-checklist"', $body);

        // Spacing: the banner wrapper uses mb-2 (not mb-4) when both
        // banner + panel render together.
        self::assertMatchesRegularExpression(
            '~<div class="rounded-2xl[^"]*\bmb-2\b[^"]*"[^>]*data-testid="domain-status-banner"~',
            $body,
        );
    }
}

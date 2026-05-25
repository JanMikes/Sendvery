<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MailboxConnection;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
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
        self::assertStringContainsString('of 5 checks passing', $body);
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

    #[Test]
    public function task107RuaRowUsesRoutingGlyphForNoRecordScenario(): void
    {
        // TASK-107: scenario NoRecord — the 5th row reads "Missing" tone but
        // must still use the routing-arrow glyph + "Where reports go" pre-
        // label so the user reads it as a routing choice, not a DNS record
        // to publish.
        $this->assertRuaRowUsesRoutingGlyph(null);
    }

    #[Test]
    public function task107RuaRowUsesRoutingGlyphForPointsAtSendveryScenario(): void
    {
        // TASK-107: scenario PointsAtSendvery — the 5th row reads success
        // tone but must still differentiate from the four protocol rows.
        $this->assertRuaRowUsesRoutingGlyph('v=DMARC1; p=none; rua=mailto:reports@sendvery.com');
    }

    #[Test]
    public function task107RuaRowUsesRoutingGlyphForPointsAtExternalScenario(): void
    {
        // TASK-107: scenario PointsAtExternal without a matching mailbox —
        // the 5th row reads warning tone but still uses the routing glyph.
        $this->assertRuaRowUsesRoutingGlyph('v=DMARC1; p=none; rua=mailto:reports@external.example');
    }

    #[Test]
    public function task107RuaRowUsesRoutingGlyphForPointsAtExternalWithMatchingMailbox(): void
    {
        // TASK-107 + TASK-114 union: the success branch (matching mailbox)
        // also gets the routing glyph. Different code path (all-green
        // forced into checklist via ruaRoutedToConnectedMailbox) so worth
        // a dedicated assertion.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $this->seedHealthyDomain($fixtures);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $ruaEmail = 'route-'.substr(Uuid::uuid7()->toString(), 0, 6).'@external.example';
        $this->persistDmarcCheck($em, $persona, sprintf('v=DMARC1; p=none; rua=mailto:%s', $ruaEmail));
        $this->persistConnectedMailbox($em, $persona, $ruaEmail);

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('data-glyph="route"', $body);
        self::assertStringContainsString('Where reports go', $body);
        self::assertStringContainsString('domain-setup-row-routing', $body);
        // The four DNS protocol rows still render check (these are
        // configured/green in this fixture) — proves we didn't replace
        // every glyph with the arrow.
        self::assertStringContainsString('data-glyph="check"', $body);
    }

    private function assertRuaRowUsesRoutingGlyph(?string $rawDmarcRecord): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $this->seedPartialDomain($fixtures);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        if (null !== $rawDmarcRecord) {
            $this->persistDmarcCheck($em, $persona, $rawDmarcRecord);
        }

        $client->loginUser($persona->user);
        assert(null !== $persona->domain);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Routing glyph + categorical pre-label fingerprint the RUA row.
        self::assertStringContainsString('data-glyph="route"', $body);
        self::assertStringContainsString('Where reports go', $body);
        self::assertStringContainsString('domain-setup-row-routing', $body);

        // The four DNS protocol rows still use the check/cross idiom (the
        // partial domain fixture has SPF + DMARC + DKIM Configured, MX
        // configured — at least one check glyph fires; or cross for the
        // ones still missing).
        self::assertTrue(
            str_contains($body, 'data-glyph="check"') || str_contains($body, 'data-glyph="cross"'),
            'Expected the four DNS protocol rows to still use check/cross glyphs alongside the new routing arrow',
        );
    }

    #[Test]
    public function task114MatchingConnectedMailboxFlipsRuaRowToSuccessTone(): void
    {
        // TASK-114 cross-surface fix: a domain whose published rua= points
        // at an external address THAT MATCHES a connected mailbox login
        // must NOT render the yellow "Configured for external inbox"
        // warning on `/app/domains/{id}` — the matching mailbox means
        // reports physically arrive via that mailbox, and the matrix on
        // `/app/mailboxes` paints this domain green.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $this->seedHealthyDomain($fixtures);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $ruaEmail = 'dmarc-'.substr(Uuid::uuid7()->toString(), 0, 6).'@external.example';
        $this->persistDmarcCheck($em, $persona, sprintf('v=DMARC1; p=none; rua=mailto:%s', $ruaEmail));
        $this->persistConnectedMailbox($em, $persona, $ruaEmail);

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Success-tone copy on the 5th row + headline + panel lede.
        self::assertStringContainsString('Routed to your connected mailbox', $body);
        self::assertStringContainsString($ruaEmail, $body);
        self::assertStringContainsString('Monitoring active — reports arriving via your connected mailbox', $body);
        self::assertStringContainsString('your connected mailbox', $body);

        // Regression guard: the yellow warning copy MUST NOT appear.
        self::assertStringNotContainsString('choose where reports land', $body);
        self::assertStringNotContainsString('Pointing at '.$ruaEmail.' — connect that inbox', $body);
    }

    #[Test]
    public function task114CrossSurfaceMailboxAndDomainAgreeOnSuccessTone(): void
    {
        // Load-bearing pin: render BOTH `/app/mailboxes` AND
        // `/app/domains/{id}` for the SAME domain (path=mailbox + recent
        // lastReportAt + scenario=PointsAtExternal + matching rua = mailbox
        // login) and assert both surfaces tell the same story (success).
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $this->seedHealthyDomain($fixtures);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $ruaEmail = 'cross-'.substr(Uuid::uuid7()->toString(), 0, 6).'@external.example';
        $this->persistDmarcCheck($em, $persona, sprintf('v=DMARC1; p=none; rua=mailto:%s', $ruaEmail));
        $this->persistConnectedMailbox($em, $persona, $ruaEmail);
        // A DMARC report attached to the mailbox so the matrix sees a
        // `path=mailbox` with `lastReportAt` recent → the TASK-106 path
        // classifier promotes the row to the green "Ingesting via mailbox"
        // badge.
        $this->persistMailboxReport($em, $persona);

        $client->loginUser($persona->user);

        // /app/domains/{id} — the 5th RUA row renders in success tone.
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));
        self::assertResponseIsSuccessful();
        $domainBody = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Routed to your connected mailbox', $domainBody);
        // The yellow warning copy for the same scenario must NOT appear —
        // proves the matcher actually flipped the badge.
        self::assertStringNotContainsString('Configured for external inbox', $domainBody);

        // /app/mailboxes — the matrix already paints this row green via
        // TASK-106. Both surfaces agree.
        $client->request('GET', '/app/mailboxes');
        self::assertResponseIsSuccessful();
        $mailboxesBody = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ingesting via mailbox', $mailboxesBody);
    }

    private function seedPartialDomain(TestFixtures $fixtures): Persona
    {
        // Partial-setup domain so the panel renders in the checklist branch
        // (TASK-107's routing-glyph fingerprint lives there). SPF verified,
        // DKIM/DMARC/MX all degraded enough to keep the panel non-green.
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimVerifiedAt = new \DateTimeImmutable();
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable();
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

        return $persona;
    }

    private function seedHealthyDomain(TestFixtures $fixtures): Persona
    {
        // All four DNS protocols configured so the all-green / scenario-(c)
        // branches in DomainSetupStatusResolver fire. The TASK-114 success
        // override only triggers when the four protocols are configured AND
        // scenario is PointsAtExternal AND the rua= matches a connected
        // mailbox.
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

        return $persona;
    }

    private function persistDmarcCheck(EntityManagerInterface $em, Persona $persona, ?string $rawRecord): void
    {
        assert(null !== $persona->domain);
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: null !== $rawRecord,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));
        $em->flush();
    }

    private function persistConnectedMailbox(EntityManagerInterface $em, Persona $persona, string $username): void
    {
        assert(null !== $persona->domain);
        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap.external.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt($username),
            encryptedPassword: $encryptor->encrypt('s3cret'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable('-30 days'),
            monitoredDomain: $persona->domain,
            isActive: true,
            lastPolledAt: new \DateTimeImmutable('-30 minutes'),
            lastError: null,
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();
    }

    private function persistMailboxReport(EntityManagerInterface $em, Persona $persona): void
    {
        // Persist a DmarcReport with a sourceEnvelope tied to the mailbox so
        // the matrix's `path` classifier reads `mailbox` for this domain.
        // Without this, the matrix would say `path=none` and the TASK-106
        // "Ingesting via mailbox" badge wouldn't fire — making the
        // cross-surface assertion vacuously true.
        assert(null !== $persona->domain);

        $mailbox = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(MailboxConnection::class)
            ->findOneBy(['monitoredDomain' => $persona->domain->id->toString()]);
        assert($mailbox instanceof MailboxConnection);

        $envelope = new \App\Entity\ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: \App\Value\Reports\ReportSource::ByoMailbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'DMARC report fixture',
            receivedAt: new \DateTimeImmutable('-2 hours'),
            ingestedAt: new \DateTimeImmutable('-2 hours'),
            sizeBytes: 1024,
            rawEml: 'x',
            mailboxConnection: $mailbox,
        );
        $em->persist($envelope);

        $report = new \App\Entity\DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
            dateRangeEnd: new \DateTimeImmutable(),
            policyDomain: $persona->domain->domain,
            policyAdkim: \App\Value\DmarcAlignment::Relaxed,
            policyAspf: \App\Value\DmarcAlignment::Relaxed,
            policyP: \App\Value\DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable('-1 hour'),
            sourceEnvelope: $envelope,
        );
        $report->popEvents();
        $em->persist($report);
        $em->flush();
    }
}

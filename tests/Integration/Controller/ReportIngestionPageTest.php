<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\TestSupport\FallbackCalloutStripping;
use App\Tests\WebTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * End-to-end coverage for the renamed `/app/mailboxes` page (now: "Report
 * ingestion") introduced by TASK-090. Validates the DNS-first callout layout,
 * the per-domain ingestion matrix, the misconfiguration warning, sidebar
 * label rename, and — load-bearing — the regression net that forbids
 * unqualified "Connect a mailbox" copy outside the fallback callout element.
 */
final class ReportIngestionPageTest extends WebTestCase
{
    use FallbackCalloutStripping;

    #[Test]
    public function rendersReportIngestionH1AndCalloutWithRecommendedAndFallback(): void
    {
        $data = $this->bootPersonaOnly();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('<h1', $body);
        self::assertStringContainsString('Report ingestion', $body);
        self::assertStringContainsString('Where DMARC reports arrive from email providers.', $body);
        self::assertStringContainsString('data-testid="recommended-callout"', $body);
        self::assertStringContainsString('data-testid="fallback-callout"', $body);
        // Sidebar label rename — "Ingestion".
        self::assertStringContainsString('>Ingestion<', $body);
    }

    #[Test]
    public function rendersMatrixRowsForEachDomainWithDifferentPaths(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];
        $persona = $data['persona'];

        // Domain A: DNS-only (already-attached `persona->domain`).
        // TASK-100: the matrix now reads the RUA scenario from the latest
        // DnsCheckResult, so we seed one that PointsAtSendvery (this domain
        // is delivering reports via the central inbox).
        assert(null !== $persona->domain);
        $envA = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $envA, new \DateTimeImmutable('-1 day'));
        $this->persistDnsCheck($em, $persona->domain, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');

        // Domain B: Mailbox-only with a DMARC record pointing at an
        // external inbox the user owns — TASK-100 scenario (c). Renders the
        // "Configured for external inbox" badge.
        $domainB = $this->persistDomain($em, $persona->team, 'b-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test');
        $mailbox = $this->persistMailbox($em, $persona->team);
        $envB = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $domainB, $envB, new \DateTimeImmutable('-1 day'));
        $this->persistDnsCheck($em, $domainB, 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domainB->domain);

        // Domain C: No reports yet, no DMARC check — scenario resolver
        // returns NoRecord, badge shows "DMARC missing", action shows
        // "Publish RUA".
        $domainC = $this->persistDomain($em, $persona->team, 'c-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test');

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('data-testid="ingestion-matrix"', $body);
        // Each domain name appears in the table.
        self::assertStringContainsString($persona->domain->domain, $body);
        self::assertStringContainsString($domainB->domain, $body);
        self::assertStringContainsString($domainC->domain, $body);
        // TASK-100 scenario-aware badges: (a) PointsAtSendvery → green DNS
        // ingesting badge, (c) PointsAtExternal → external-inbox warning,
        // NoRecord → red "DMARC missing".
        self::assertStringContainsString('Ingesting via DNS (Sendvery)', $body);
        self::assertStringContainsString('Configured for external inbox', $body);
        self::assertStringContainsString('DMARC missing', $body);
        // The "no reports yet" domain still gets a "Publish RUA" CTA.
        self::assertStringContainsString('Publish RUA', $body);
    }

    #[Test]
    public function mixedIngestionRowCarriesMixedWarningTestId(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $envCentral = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $envCentral, new \DateTimeImmutable('-1 day'));

        $mailbox = $this->persistMailbox($em, $persona->team);
        $envMb = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-2 days'));
        $this->persistReport($em, $persona->domain, $envMb, new \DateTimeImmutable('-2 days'));

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('data-testid="mixed-warning"', $body);
        self::assertStringContainsString('Both — please pick one', $body);
    }

    /**
     * Regression for the unverified-single-domain shortcut: when a team has
     * exactly one domain that has NEVER received a report, the recommended
     * "View DMARC setup" CTA must NOT deep-link to the per-domain health page
     * (which would be empty). It must fall through to the DNS overview.
     */
    #[Test]
    public function unverifiedSingleDomainTeamCtaPointsToDnsOverviewNotDomainHealth(): void
    {
        $data = $this->bootPersonaOnly();
        // The persona is built with exactly one domain and zero reports —
        // `IngestionPath::None`. The CTA must skip the deep-link branch.

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        self::assertStringContainsString('data-testid="recommended-callout"', $body);
        self::assertStringContainsString('href="/app/domains"', $body);
        // No per-domain deep link to /app/domains/{uuid}/health for the
        // recommended callout in this state.
        self::assertDoesNotMatchRegularExpression(
            '#href="/app/domains/[^"]+/health"#',
            $this->extractRecommendedCalloutHtml($body),
        );
    }

    /**
     * Counterpart: when the single domain DOES have a recent report (any
     * path other than None), the CTA deep-links to that domain's health page.
     */
    #[Test]
    public function verifiedSingleDomainTeamCtaDeepLinksToDomainHealth(): void
    {
        $data = $this->bootPersonaOnly();
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        // Give the persona's only domain one DNS-backed report so the matrix
        // classifies it as `IngestionPath::Dns`, not `None`.
        $env = $this->persistEnvelope($data['em'], ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($data['em'], $persona->domain, $env, new \DateTimeImmutable('-1 day'));

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        self::assertStringContainsString(
            'href="/app/domains/'.$persona->domain->id->toString().'/health"',
            $this->extractRecommendedCalloutHtml($body),
        );
    }

    #[Test]
    public function connectedMailboxesSectionHiddenWhenZeroMailboxes(): void
    {
        $data = $this->bootPersonaOnly();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('data-testid="connected-mailboxes"', $body);
    }

    #[Test]
    public function connectedMailboxesSectionVisibleWhenAtLeastOneMailbox(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];

        $this->persistMailbox($em, $data['persona']->team);

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('data-testid="connected-mailboxes"', $body);
        self::assertStringContainsString('Connected mailboxes', $body);
    }

    /**
     * Regression net: the literal substrings "Connect a mailbox" /
     * "Connect mailbox" / "Add mailbox" MUST appear only inside the fallback
     * callout. If a future refactor re-introduces an unqualified mailbox CTA
     * elsewhere on the page (EmptyState, header button, etc.), this test
     * catches it before it ships.
     */
    #[Test]
    public function unqualifiedMailboxCopyForbiddenOutsideFallbackCallout(): void
    {
        $data = $this->bootPersonaOnly();
        // Persist a mailbox so the "Connected mailboxes" section also renders —
        // proves the regression net works on the fully-populated page, not
        // only on the empty state.
        $this->persistMailbox($data['em'], $data['persona']->team);

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Strip the fallback callout AND the global add dropdown (a
        // layout-level affordance present on every dashboard page; not part
        // of the page's content hierarchy and intentionally exempt). What
        // remains is the page's own content surface — the regression net
        // catches any in-page mailbox-first CTA introduced there. The
        // strip is DOM-based (see FallbackCalloutStripping) so it stays
        // correct as the callout's nesting depth evolves.
        $stripped = $this->stripFallbackCalloutAndGlobalDropdown($body);

        // Sanity check: the substring exists in the original body (inside the
        // fallback callout) but is absent from the stripped page content. The
        // "Connect mailbox" check is regex-bounded so the "Connected
        // mailboxes" section heading (legitimately present elsewhere) doesn't
        // false-positive — we want the standalone CTA, not the noun phrase.
        self::assertStringContainsString('Connect a mailbox', $body);
        self::assertStringNotContainsString('Connect a mailbox', $stripped);
        self::assertDoesNotMatchRegularExpression('/\bConnect mailbox\b/', $stripped);
        self::assertStringNotContainsString('Add mailbox', $stripped);
    }

    /**
     * TASK-105: every matrix row already routes via Sendvery — the two-card
     * IngestionRoutesCallout collapses to a single confirmation card. The
     * "Connect a mailbox (fallback)" header must not appear anywhere on the
     * page; the new collapsed-callout testid must.
     */
    #[Test]
    public function allScenarioBTeamCollapsesIngestionRoutesCalloutToConfirmation(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        // Persona's only domain: DNS-backed with a Sendvery-pointing rua tag.
        $env = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $env, new \DateTimeImmutable('-1 day'));
        $this->persistDnsCheck($em, $persona->domain, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Collapsed confirmation card present.
        self::assertStringContainsString('data-testid="all-scenario-b-callout"', $body);
        self::assertStringContainsString("Every domain is ingesting reports via Sendvery's central inbox", $body);
        self::assertStringContainsString('Connect a private mailbox anyway', $body);

        // Two-card layout suppressed — neither testid present.
        self::assertStringNotContainsString('data-testid="recommended-callout"', $body);
        self::assertStringNotContainsString('data-testid="fallback-callout"', $body);
        // The fallback card's header copy is gone.
        self::assertStringNotContainsString('Connect a mailbox (fallback)', $body);
    }

    /**
     * Counterpart: as soon as one domain isn't scenario (b) the two-card
     * layout returns. Mixed teams + scenario-(c) teams + brand-new teams all
     * still get the educational split — only the homogeneous all-(b) state
     * collapses.
     */
    #[Test]
    public function mixedScenarioTeamKeepsTwoCardIngestionRoutesCallout(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        // Domain 1: scenario (b)
        $envA = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $envA, new \DateTimeImmutable('-1 day'));
        $this->persistDnsCheck($em, $persona->domain, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');

        // Domain 2: scenario (c) — external rua
        $domainB = $this->persistDomain($em, $persona->team, 'b-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test');
        $this->persistDnsCheck($em, $domainB, 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domainB->domain);

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Two-card layout intact.
        self::assertStringContainsString('data-testid="recommended-callout"', $body);
        self::assertStringContainsString('data-testid="fallback-callout"', $body);
        // Collapsed card NOT present.
        self::assertStringNotContainsString('data-testid="all-scenario-b-callout"', $body);
    }

    /**
     * Empty team (zero domains, zero scenarios) keeps the educational
     * two-card layout because there's nothing to confirm — `allScenarioB`
     * resolves to false for an empty matrix on purpose.
     */
    #[Test]
    public function emptyTeamKeepsTwoCardIngestionRoutesCallout(): void
    {
        // The default persona has exactly one (no-report, no-DMARC) domain —
        // not strictly empty, but the resolver returns NoRecord for it, so
        // `allScenarioB` is false. The collapsed card must not appear.
        $data = $this->bootPersonaOnly();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        self::assertStringContainsString('data-testid="recommended-callout"', $body);
        self::assertStringContainsString('data-testid="fallback-callout"', $body);
        self::assertStringNotContainsString('data-testid="all-scenario-b-callout"', $body);
    }

    /**
     * TASK-106 happy path: a mailbox-backed domain whose DMARC rua= tag
     * names that same connected mailbox's login renders the "Ingesting via
     * mailbox" success badge — NOT the "Configured for external inbox"
     * warning. The "Connect this inbox" CTA is suppressed (already connected).
     */
    #[Test]
    public function mailboxPathWithMatchingRuaEmailRendersIngestingViaMailboxBadge(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $mailbox = $this->persistMailboxWithUsername(
            $em,
            $persona->team,
            'dmarc@'.$persona->domain->domain,
        );

        // path=mailbox via a recent ByoMailbox-sourced report.
        $env = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $env, new \DateTimeImmutable('-1 day'));

        // scenario=PointsAtExternal — rua points at the same address as the
        // connected mailbox.
        $this->persistDnsCheck(
            $em,
            $persona->domain,
            'v=DMARC1; p=none; rua=mailto:dmarc@'.$persona->domain->domain,
        );

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // The TASK-106 winning branch.
        self::assertStringContainsString('Ingesting via mailbox', $body);
        // TASK-116: the sub-line names the rua= address (monospace pill) so
        // the user can verify the assertion, matching surrounding branches.
        self::assertStringContainsString('DMARC routes here via', $body);
        self::assertStringContainsString('dmarc@'.$persona->domain->domain, $body);
        self::assertStringContainsString('— your connected mailbox.', $body);

        // The OLD branch must NOT render for this row — verify against a
        // matrix-scoped substring so it doesn't false-positive on, say, a
        // collapsed-callout copy elsewhere on the page.
        self::assertStringNotContainsString('Configured for external inbox', $body);
        // The "Connect this inbox" CTA is also suppressed — already connected.
        self::assertStringNotContainsString('Connect this inbox', $body);
    }

    /**
     * TASK-106 negative case: the published rua= tag points at an external
     * address that does NOT match the connected mailbox's login. Operator
     * wired the wrong inbox — keep the "Configured for external inbox"
     * warning so they notice.
     */
    #[Test]
    public function mailboxPathWithNonMatchingRuaEmailKeepsExternalInboxWarning(): void
    {
        $data = $this->bootPersonaOnly();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        // Mailbox login is `notifications@…` but the rua tag says `dmarc@…`.
        $mailbox = $this->persistMailboxWithUsername(
            $em,
            $persona->team,
            'notifications@'.$persona->domain->domain,
        );

        $env = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $env, new \DateTimeImmutable('-1 day'));

        $this->persistDnsCheck(
            $em,
            $persona->domain,
            'v=DMARC1; p=none; rua=mailto:dmarc@'.$persona->domain->domain,
        );

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Scenario-aware warning still wins.
        self::assertStringContainsString('Configured for external inbox', $body);
        self::assertStringNotContainsString('Ingesting via mailbox', $body);
    }

    /**
     * Pulls the recommended callout's outerHTML out of the rendered page
     * via DOM so per-CTA assertions don't get false positives from the
     * fallback callout's anchor (which has a different href).
     */
    private function extractRecommendedCalloutHtml(string $body): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[@data-testid="recommended-callout"]');
        assert($nodes instanceof \DOMNodeList);
        self::assertGreaterThan(0, $nodes->length, 'recommended-callout not found in page');

        $node = $nodes->item(0);
        assert($node instanceof \DOMNode);

        $html = $dom->saveHTML($node);
        assert(is_string($html));

        return $html;
    }

    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, persona: Persona}
     */
    private function bootPersonaOnly(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('rpt-ing-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('rpt-ing.example')
            ->build();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
        ];
    }

    private function persistDomain(EntityManagerInterface $em, Team $team, string $name): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    private function persistMailbox(EntityManagerInterface $em, Team $team): MailboxConnection
    {
        return $this->persistMailboxWithUsername($em, $team, 'u');
    }

    /**
     * Variant of {@see persistMailbox} that sets a caller-supplied username
     * (encrypted at rest). TASK-106 uses this to seed a mailbox whose login
     * matches — or doesn't — the rua= email in a parallel DMARC record.
     */
    private function persistMailboxWithUsername(
        EntityManagerInterface $em,
        Team $team,
        string $username,
    ): MailboxConnection {
        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.rpt-ing.test',
            port: 993,
            encryptedUsername: $encryptor->encrypt($username),
            encryptedPassword: $encryptor->encrypt('p'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        return $mailbox;
    }

    private function persistEnvelope(
        EntityManagerInterface $em,
        ReportSource $source,
        ?MailboxConnection $mailbox,
        \DateTimeImmutable $receivedAt,
    ): ReceivedReportEmail {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: $source,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'page fixture',
            receivedAt: $receivedAt,
            ingestedAt: $receivedAt,
            sizeBytes: 1024,
            rawEml: 'x',
            mailboxConnection: $mailbox,
        );
        $em->persist($envelope);
        $em->flush();

        return $envelope;
    }

    private function persistDnsCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        ?string $rawRecord,
    ): DnsCheckResult {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: null !== $rawRecord,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();

        return $check;
    }

    private function persistReport(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        ReceivedReportEmail $envelope,
        \DateTimeImmutable $processedAt,
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $processedAt,
            dateRangeEnd: $processedAt,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: $processedAt,
            sourceEnvelope: $envelope,
        );
        $report->popEvents();
        $em->persist($report);
        $em->flush();

        return $report;
    }
}

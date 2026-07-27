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
 * Covers the two stacked verdict surfaces on /app/domains/{id}: the one-line
 * status banner up top and the guided DNS setup surface directly under
 * DomainWorkspaceTabs. Also guards the regression that the old bare
 * SPF/DKIM/DMARC/MX badge chips are gone.
 *
 * The guided surface replaced a flat "N of 5 checks passing" checklist. Its
 * contract is different in three ways the tests below pin down: exactly one
 * step is ever presented as actionable now, the DMARC record and where its
 * reports go are ONE step rather than two rows, and both report-delivery paths
 * (self-managed TXT, managed CNAME) are always offered.
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
    public function spfFailingShowsAttentionBannerAndNamesSpfAsTheOneThingToDoNext(): void
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

        // Report delivery already works for this domain, so it cannot claim the
        // single actionable slot — that leaves SPF as the next thing to do.
        $this->persistDmarcCheck($em, $persona, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Banner — Attention headline mentions SPF + warning tone.
        self::assertStringContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringContainsString('Action needed', $body);
        self::assertStringContainsString('SPF', $body);

        // Report delivery is fine here, so SPF is the single step presented as
        // actionable now — the whole point of the tiering is that the user is
        // pointed at ONE thing rather than a wall of equally red rows.
        self::assertStringContainsString('data-testid="guided-setup-step-spf"', $body);
        self::assertStringContainsString('Action required now', $body);
        self::assertStringNotContainsString('data-testid="guided-setup-step-delivery"', $body);

        // The old checklist framing is gone for good.
        self::assertStringNotContainsString('of 5 checks passing', $body);
        self::assertStringNotContainsString('data-testid="domain-setup-status-checklist"', $body);
    }

    #[Test]
    public function onlyOneStepIsEverPresentedAsActionableNow(): void
    {
        // A domain where several records are unfinished at once. Whatever else
        // renders, tier 1 holds exactly one step: the ambiguity the redesign
        // set out to remove was "four red rows, which do I touch first?".
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        // A single stored check is enough to leave the pending state; nothing is
        // verified, so report delivery, SPF, DKIM and MX are all outstanding.
        $this->persistDmarcCheck($em, $persona, null);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-setup-tier="action_required"]'),
            'Exactly one step may be presented as the thing to do next.',
        );
        // Report delivery wins the slot — without it there are no reports at
        // all, so nothing else on the product works.
        self::assertCount(
            1,
            $crawler->filter('[data-testid="guided-setup-step-delivery"][data-setup-tier="action_required"]'),
            'Report delivery outranks SPF, DKIM and MX for the actionable slot.',
        );
        // The rest are visible but explicitly deferred, not hidden.
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="guided-setup-summary-later"]')->count(),
            'Outstanding-but-deferred records stay visible under their own heading.',
        );
    }

    #[Test]
    public function theReportDeliveryStepOffersBothTheTxtRecordAndTheManagedCnamePath(): void
    {
        // The complaint that drove this: the DNS surfaces asked for a TXT record
        // and never mentioned that Sendvery can host the record instead. Both
        // paths must be on the page, and a plan that cannot use the managed one
        // must be told why rather than shown nothing.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persistDmarcCheck($em, $persona, null);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-testid="report-delivery-option-self_txt"]'),
            'The self-managed TXT path must be offered.',
        );
        self::assertCount(
            1,
            $crawler->filter('[data-testid="report-delivery-option-managed_cname"]'),
            'The managed-CNAME path must be offered too, not silently omitted.',
        );
        // Unavailable is fine; unexplained is not.
        $managed = $crawler->filter('[data-testid="report-delivery-option-managed_cname"]');
        self::assertNotSame(
            '',
            trim($managed->text()),
            'The managed option must carry either an action or the reason it is unavailable.',
        );
    }

    #[Test]
    public function noDnsCheckYetShowsAnInProgressPanelInsteadOfRedVerdicts(): void
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

        // The banner hides while there is no verdict — the old "DNS not
        // configured yet" headline was a wrong-information bug a first-time
        // user hit within five minutes of signing up.
        self::assertStringNotContainsString('data-testid="domain-status-banner"', $body);
        self::assertStringNotContainsString('DNS not configured yet — start with the SPF record', $body);

        // The in-progress panel says a check is running, and offers the manual
        // escape hatch. Crucially it must NOT claim any record is missing.
        self::assertStringContainsString('data-testid="dns-check-pending-banner"', $body);
        self::assertStringContainsString('DNS check in progress', $body);
        self::assertStringNotContainsString('not detected', $body);
        self::assertMatchesRegularExpression(
            '~<form[^>]*action="/app/domains/[^"]+/reverify"~',
            $body,
        );
    }

    #[Test]
    public function theInProgressPanelKeepsItselfUpToDateWithoutAReload(): void
    {
        // A user who added a domain and waited saw the page silently flip to red
        // only after they reloaded by hand. The pending panel now polls a
        // turbo-frame endpoint and settles on its own.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $extra = $fixtures->addExtraDomain($persona->team, 'polling-extra');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $extra->id->toString()));

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#domain-dns-setup');
        self::assertCount(1, $frame, 'The setup surface lives in a turbo-frame so it can refresh in place.');
        self::assertStringContainsString(
            'dns-verify-poll',
            (string) $frame->attr('data-controller'),
            'While a check is pending the frame polls for the result.',
        );
        self::assertStringContainsString(
            sprintf('/app/domains/%s/dns-setup', $extra->id->toString()),
            (string) $frame->attr('data-dns-verify-poll-url-value'),
            'It polls the per-domain setup frame endpoint.',
        );

        // And that endpoint renders the same surface, so a polled refresh cannot
        // drift from what a reload would show.
        $client->request('GET', sprintf('/app/domains/%s/dns-setup?mode=compact', $extra->id->toString()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'data-testid="dns-check-pending-banner"',
            (string) $client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function pollingStopsOnceTheCheckHasProducedAResult(): void
    {
        // The stopping condition has to be in the markup, not only in the JS:
        // the settled marker is what tells the poller to go quiet, so a settled
        // surface must never advertise the poller.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persistDmarcCheck($em, $persona, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-dns-setup-settled]')->count(),
            'A surface with real results marks itself settled so polling stops.',
        );
        self::assertNull(
            $crawler->filter('turbo-frame#domain-dns-setup')->attr('data-controller'),
            'Nothing left to wait for means no poller at all.',
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

        // The old panel's three branches must all be gone.
        self::assertStringNotContainsString('data-testid="domain-setup-status-all-green"', $body);
        self::assertStringNotContainsString('data-testid="domain-setup-status-checklist"', $body);
        self::assertStringNotContainsString('data-testid="domain-setup-status-pending"', $body);
        // No second "DNS setup is complete" duplicate headline.
        self::assertStringNotContainsString('DNS setup is complete', $body);
    }

    #[Test]
    public function publishingTheDmarcRecordAndPointingItsReportsAtSendveryIsOneStepNotTwo(): void
    {
        // The old surface split these into a "DMARC" row and a separate "RUA
        // destination" row, which read as two DNS records to publish. It is one
        // record and one job, so it is one step — and the step is named after
        // the outcome the user cares about.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $this->seedPartialDomain($fixtures);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        // A DMARC record exists but its rua= goes somewhere else — the case
        // where the two old rows disagreed most confusingly.
        $this->persistDmarcCheck($em, $persona, 'v=DMARC1; p=none; rua=mailto:reports@external.example');

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertCount(
            1,
            $crawler->filter('[data-testid="guided-setup-step-delivery"]'),
            'Report delivery is a single step.',
        );
        // The old two-row framing (and its routing-arrow workaround for the
        // ambiguity) is gone.
        self::assertStringNotContainsString('Where reports go', $body);
        self::assertStringNotContainsString('domain-setup-row-routing', $body);

        // Editing beats adding here: a record already exists at `_dmarc`, and
        // telling the user to "add" one is how domains end up with two.
        self::assertStringContainsString('Edit the existing record', $body);
        // The record it hands over keeps the user's existing address and appends
        // ours rather than silently replacing theirs.
        self::assertStringContainsString('mailto:reports@external.example,mailto:', $body);
    }

    #[Test]
    public function theRecordIsPresentedWithTheFieldsADnsProviderAsksFor(): void
    {
        // Cloudflare and friends ask for type, name, TTL and content. Naming
        // those fields — and showing the short host label rather than only the
        // FQDN — is what stops the classic `_dmarc.example.com.example.com`.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persistDmarcCheck($em, $persona, null);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $record = $crawler->filter('[data-testid="guided-dns-record"]');
        self::assertGreaterThan(0, $record->count(), 'The actionable step shows a record to publish.');

        $text = $record->text();
        foreach (['Type', 'Name', 'TTL', 'Current value', 'Final value'] as $label) {
            self::assertStringContainsString($label, $text, sprintf('The record block labels its "%s" field.', $label));
        }
        self::assertStringContainsString('Add a new record', $text, 'Nothing is published yet, so the verb is "add".');
        self::assertStringContainsString('No record exists yet', $text, 'The current value is stated rather than left blank.');
        self::assertStringContainsString('_dmarc', $text, 'The host is shown as the short label the provider expects.');
        self::assertGreaterThan(
            0,
            $record->filter('[data-testid="guided-dns-record-copy"]')->count(),
            'The value the user has to transcribe is copyable.',
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

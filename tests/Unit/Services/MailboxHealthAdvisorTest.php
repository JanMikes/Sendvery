<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Results\Dns\RuaScenarioResult;
use App\Results\MailboxActivitySummary;
use App\Services\MailboxHealthAdvisor;
use App\Services\ReportAddressProvider;
use App\Value\Dns\RuaScenario;
use App\Value\MailboxEncryption;
use App\Value\MailboxHealthSeverity;
use App\Value\MailboxType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

/**
 * Pure-computation coverage for the {@see MailboxHealthAdvisor} branches
 * (TASK-094). The advisor is the only place that decides which (if any) of
 * the three advisory cards renders on `/app/mailboxes/{id}` — locking the
 * eligibility rules here keeps the dashboard surface deterministic across
 * every "what should this user do?" surface in the app.
 */
final class MailboxHealthAdvisorTest extends TestCase
{
    private const NOW = '2026-05-24 10:00:00';

    #[Test]
    public function brokenCredentialsWhenLastErrorPresentAndPolledRecently(): void
    {
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: 'Authentication failed (535)',
        );

        $result = $advisor->advise($mailbox, MailboxActivitySummary::empty());

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::BrokenCredentials, $result->severity);
        self::assertStringContainsString('Authentication failed (535)', $result->reasonText);
        self::assertStringContainsString('May 24, 09:30', $result->reasonText);
        self::assertSame('Re-test connection', $result->primaryActionLabel);
        self::assertSame('dashboard_mailbox_retest', $result->primaryActionRoute);
        self::assertSame(['id' => $mailbox->id->toString()], $result->primaryActionRouteParams);
        self::assertNull($result->secondaryActionLabel);
    }

    #[Test]
    public function brokenCredentialsFiresEvenWhenLastErrorIsStale(): void
    {
        // Edge case from TASK-094 spec: lastError set, lastPolledAt was 3 days
        // ago. Spec ambiguity (24h gate vs "should still flag broken_credentials")
        // resolved in favour of always-flag — a stale credentials error has
        // not self-healed, and treating it as healthy would mislead the user.
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-21 09:30:00'),
            lastError: 'Connection timed out',
        );

        $result = $advisor->advise($mailbox, MailboxActivitySummary::empty());

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::BrokenCredentials, $result->severity);
        self::assertStringContainsString('Connection timed out', $result->reasonText);
    }

    #[Test]
    public function brokenCredentialsFallsBackWhenLastPolledAtIsNull(): void
    {
        // Defensive branch: if the first poll attempt erred before stamping
        // lastPolledAt, the reason text must not render the literal "null".
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-23 09:00:00'),
            lastPolledAt: null,
            lastError: 'Hostname unresolved',
        );

        $result = $advisor->advise($mailbox, MailboxActivitySummary::empty());

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::BrokenCredentials, $result->severity);
        self::assertStringContainsString('the most recent attempt', $result->reasonText);
        self::assertStringNotContainsString('null', $result->reasonText);
    }

    #[Test]
    public function silentForTooLongWhenAlivePolledOver7DaysWithNoEnvelopes(): void
    {
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-10 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
        );

        $result = $advisor->advise($mailbox, new MailboxActivitySummary(0, 0, 0));

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::SilentForTooLong, $result->severity);
        self::assertStringContainsString('7+ days', $result->reasonText);
        self::assertStringContainsString('reports@sendvery.test', $result->reasonText);
        self::assertSame('Check DNS', $result->primaryActionLabel);
        self::assertSame('dashboard_dns_health', $result->primaryActionRoute);
        self::assertSame([], $result->primaryActionRouteParams);
        self::assertSame('Use DNS-based ingestion instead', $result->secondaryActionLabel);
        self::assertSame('dashboard_mailboxes', $result->secondaryActionRoute);
    }

    #[Test]
    public function silentForTooLongEnrichedWhenLinkedDomainPointsAtExternal(): void
    {
        $advisor = $this->makeAdvisor();
        $domain = $this->makeDomain('example.com');
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-10 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
            monitoredDomain: $domain,
        );

        $result = $advisor->advise(
            $mailbox,
            new MailboxActivitySummary(0, 0, 0),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@external.example'),
        );

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::SilentForTooLong, $result->severity);
        self::assertStringContainsString('example.com', $result->reasonText);
        self::assertStringContainsString('reports@external.example', $result->reasonText);
    }

    #[Test]
    public function silentForTooLongCopyIgnoresScenarioWhenMailboxIsTeamShared(): void
    {
        // Mailbox with no monitoredDomain link — the linked-domain enrichment
        // must NOT render (would be incoherent for a team-shared inbox).
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-10 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
            monitoredDomain: null,
        );

        $result = $advisor->advise(
            $mailbox,
            new MailboxActivitySummary(0, 0, 0),
            // Even when a scenario is passed, the missing entity link must suppress it.
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@external.example'),
        );

        self::assertNotNull($result);
        self::assertStringNotContainsString('reports@external.example', $result->reasonText);
    }

    #[Test]
    public function quarantineDominantWhenOverHalfOfTenPlusEnvelopesAreQuarantined(): void
    {
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
        );

        $result = $advisor->advise($mailbox, new MailboxActivitySummary(20, 8, 12));

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::QuarantineDominant, $result->severity);
        self::assertSame('Open quarantine for this mailbox', $result->primaryActionLabel);
        self::assertSame('dashboard_quarantine', $result->primaryActionRoute);
        self::assertSame(['mailbox' => $mailbox->id->toString()], $result->primaryActionRouteParams);
    }

    #[Test]
    public function quarantineDominantSuppressedBelowEnvelopeFloor(): void
    {
        // 5 of 9 envelopes quarantined — meets the >50% rule but not the >=10
        // floor; suppressed because "5 of 9" is too noisy a signal.
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
        );

        $result = $advisor->advise($mailbox, new MailboxActivitySummary(9, 4, 5));

        self::assertNull($result);
    }

    #[Test]
    public function healthyWhenJustCreatedAndNeverPolled(): void
    {
        // Edge case: mailbox created yesterday, no poll yet — no advisory.
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-23 09:00:00'),
            lastPolledAt: null,
            lastError: null,
        );

        $result = $advisor->advise($mailbox, MailboxActivitySummary::empty());

        self::assertNull($result);
    }

    #[Test]
    public function healthyWhenZeroEnvelopesButPolledLessThan7Days(): void
    {
        // Edge case: mailbox polling for 3 days, no envelopes yet — too early
        // to call. Returns null so the operator isn't badgered before the
        // first day-of-the-week has had a chance to deliver.
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-05-21 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
        );

        $result = $advisor->advise($mailbox, MailboxActivitySummary::empty());

        self::assertNull($result);
    }

    #[Test]
    public function healthyWhenMailboxIsInactive(): void
    {
        // Deactivated mailbox: silent_for_too_long must not fire because the
        // user explicitly paused polling.
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
            isActive: false,
        );

        $result = $advisor->advise($mailbox, MailboxActivitySummary::empty());

        self::assertNull($result);
    }

    #[Test]
    public function healthyWhenEverythingFlowing(): void
    {
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: null,
        );

        $result = $advisor->advise($mailbox, new MailboxActivitySummary(50, 48, 2));

        self::assertNull($result);
    }

    #[Test]
    public function brokenCredentialsTakesPrecedenceOverSilentForTooLong(): void
    {
        // If a long-running mailbox starts erroring out, the credentials
        // problem is the *cause* of the silence — show that, not the symptom.
        $advisor = $this->makeAdvisor();
        $mailbox = $this->makeMailbox(
            createdAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
            lastPolledAt: new \DateTimeImmutable('2026-05-24 09:30:00'),
            lastError: 'AUTH PLAIN failed',
        );

        $result = $advisor->advise($mailbox, new MailboxActivitySummary(0, 0, 0));

        self::assertNotNull($result);
        self::assertSame(MailboxHealthSeverity::BrokenCredentials, $result->severity);
    }

    private function makeAdvisor(): MailboxHealthAdvisor
    {
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $reportAddressProvider = new ReportAddressProvider('reports@sendvery.test');

        return new MailboxHealthAdvisor($clock, $reportAddressProvider);
    }

    private function makeMailbox(
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $lastPolledAt,
        ?string $lastError,
        bool $isActive = true,
        ?MonitoredDomain $monitoredDomain = null,
    ): MailboxConnection {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Acme',
            slug: 'acme-'.bin2hex(random_bytes(3)),
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $team->popEvents();

        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: $createdAt,
            monitoredDomain: $monitoredDomain,
            isActive: $isActive,
            lastPolledAt: $lastPolledAt,
            lastError: $lastError,
        );
        $mailbox->popEvents();

        return $mailbox;
    }

    private function makeDomain(string $name): MonitoredDomain
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Acme',
            slug: 'acme-'.bin2hex(random_bytes(3)),
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
        );
        $domain->popEvents();

        return $domain;
    }
}

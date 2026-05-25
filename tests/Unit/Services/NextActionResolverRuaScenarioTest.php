<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainIngestionMatrixResult;
use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Services\NextActionResolver;
use App\Value\Dns\RuaScenario;
use App\Value\DomainVerificationSeverity;
use App\Value\IngestionPath;
use App\Value\NextAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TASK-100: scenario-driven next-action branches. Covers the three new
 * code paths in {@see NextActionResolver}:
 *   (a) NoRecord — unchanged from pre-TASK-100 (PublishRuaRecord fallback)
 *   (b) PointsAtSendvery — falls through to AllHealthy even with no reports
 *   (c) PointsAtExternal — emits the new ConnectExternalMailbox action.
 *
 * Plus the regression guard that scenario (c) must never echo the banned
 * "Connect a mailbox" copy that the fallback-callout regression net forbids.
 */
final class NextActionResolverRuaScenarioTest extends TestCase
{
    #[Test]
    public function publishRuaRecordUnchangedWhenScenarioIsNoRecord(): void
    {
        // Scenario (a): with a NoRecord scenario, the resolver behaves
        // exactly as it did before TASK-100 — PublishRuaRecord in the
        // 7-day grace, ConnectMailbox after.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 0)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::NoRecord, null),
        );

        self::assertSame(NextAction::PublishRuaRecord, $result->actionKey);
    }

    #[Test]
    public function publishRuaRecordSkippedWhenScenarioIsPointsAtSendvery(): void
    {
        // Scenario (b) settled: DMARC routes to Sendvery AND the first report
        // has already landed. No CTA — fall through to AllHealthy. Uses the
        // per-domain map path (firstReportAt set on the domain) so the test
        // exercises the same priority pipeline production hits.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 100, firstReportAt: '2026-05-15 09:00:00')],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            domainRuaScenarios: [
                'domain-id' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            ],
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
        self::assertSame('success', $result->severity);
    }

    #[Test]
    public function scenarioPointsAtSendveryWithoutFirstReportEmitsWaitForReportsNotAllHealthy(): void
    {
        // TASK-102 regression: a freshly-verified scenario-(b) domain that's
        // received zero reports yet must NOT see "All your domains are
        // healthy and reports are flowing" — that's the round-3-class lie.
        // The scenario-(b) shortcut should defer to WaitForReports until
        // the first report actually lands.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 0)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-1 day'),
                firstReportAt: null,
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-1 day'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable(),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
        );

        self::assertSame(NextAction::WaitForReports, $result->actionKey);
        self::assertStringNotContainsString('reports are flowing', $result->description);
        self::assertStringContainsString('24-48', $result->description);
    }

    #[Test]
    public function connectExternalMailboxEmittedWhenScenarioIsPointsAtExternal(): void
    {
        // Scenario (c): DMARC sends reports to an external inbox the user
        // owns. The resolver should emit ConnectExternalMailbox, name the
        // external address in the title + description, and surface the
        // "or repoint DMARC to Sendvery" alternative in the secondary CTA.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 0)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
        );

        self::assertSame(NextAction::ConnectExternalMailbox, $result->actionKey);
        self::assertStringContainsString('reports@acme.com', $result->title);
        self::assertStringContainsString('reports@acme.com', $result->description);
        self::assertStringContainsString('or update the DMARC record', $result->description);
        self::assertSame('Connect this inbox', $result->ctaLabel);
        self::assertSame('dashboard_mailbox_add', $result->ctaRoute);
        self::assertSame('Or repoint DMARC to Sendvery', $result->secondaryCtaLabel);
        self::assertSame('dashboard_domains', $result->secondaryCtaRoute);
        self::assertSame('info', $result->severity);
    }

    #[Test]
    public function connectExternalMailboxIgnoresDismissedAndSevenDaysPassed(): void
    {
        // The PointsAtExternal branch is a SCENARIO-SPECIFIC recommendation,
        // not a generic "no reports yet" fallback — so the dismiss flag
        // and 7-day grace window must NOT bypass it. Even with both set,
        // the resolver still emits ConnectExternalMailbox.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 0)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-30 days'),
                firstReportAt: new \DateTimeImmutable('-29 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-04-01 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: new \DateTimeImmutable('2026-05-23 14:00:00'),
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@acme.com'),
        );

        self::assertSame(NextAction::ConnectExternalMailbox, $result->actionKey);
    }

    #[Test]
    public function connectExternalMailboxNeverSaysConnectAMailbox(): void
    {
        // Regression net for the TASK-090 mailbox-first copy ban. The
        // ConnectExternalMailbox branch must never emit any of the three
        // banned strings; the qualified "Connect this inbox" is the
        // approved alternative.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 0)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
        );

        $haystack = $result->title.' '.$result->description.' '.$result->ctaLabel;
        self::assertStringNotContainsString('Connect a mailbox', $haystack);
        self::assertStringNotContainsString('Connect mailbox', $haystack);
        self::assertStringNotContainsString('Add mailbox', $haystack);
    }

    #[Test]
    public function multiDomainTeamWithNoRecordDomainWinsOverPointsAtSendveryDomain(): void
    {
        // TASK-129 priority: NoRecord beats PointsAtSendvery. A team with a
        // healthy Sendvery-routed domain AND a second domain that still has
        // no DMARC at all must surface the PublishRuaRecord nudge, not
        // "all healthy" — fixing the missing record is the higher-value step.
        $resolver = new NextActionResolver();

        $domainA = $this->buildDomain(domainId: 'dom-a', domainName: 'sendvery.example', totalReports: 100);
        $domainB = $this->buildDomain(domainId: 'dom-b', domainName: 'unconfigured.example', totalReports: 0);

        $result = $resolver->resolve(
            domains: [$domainA, $domainB],
            verificationStatus: $this->buildStatus(
                domainId: 'dom-a',
                domainName: 'sendvery.example',
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('sendvery.example', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            domainRuaScenarios: [
                'dom-a' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
                'dom-b' => new RuaScenarioResult(RuaScenario::NoRecord, null),
            ],
        );

        self::assertSame(NextAction::PublishRuaRecord, $result->actionKey);
    }

    #[Test]
    public function multiDomainTeamWithPointsAtExternalWinsOverPointsAtSendvery(): void
    {
        // TASK-129 priority: PointsAtExternal beats PointsAtSendvery. A team
        // with one DNS-routed Sendvery domain and one rua=elsewhere domain
        // must surface the ConnectExternalMailbox nudge for the external one.
        $resolver = new NextActionResolver();

        $domainA = $this->buildDomain(domainId: 'dom-a', domainName: 'sendvery.example', totalReports: 50);
        $domainB = $this->buildDomain(domainId: 'dom-b', domainName: 'external.example', totalReports: 0);

        $result = $resolver->resolve(
            domains: [$domainA, $domainB],
            verificationStatus: $this->buildStatus(
                domainId: 'dom-a',
                domainName: 'sendvery.example',
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('sendvery.example', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            domainRuaScenarios: [
                'dom-a' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
                'dom-b' => new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@external.example'),
            ],
        );

        self::assertSame(NextAction::ConnectExternalMailbox, $result->actionKey);
        self::assertStringContainsString('dmarc@external.example', $result->title);
    }

    #[Test]
    public function multiDomainTeamWithMixedPointsAtSendveryHealthEmitsWaitForReports(): void
    {
        // TASK-129: when EVERY domain is PointsAtSendvery but at least one
        // is still waiting on its first report (totalReports === 0), the
        // card defers to WaitForReports — telling the user "everything is
        // flowing" while one domain has zero data would be the round-3 lie.
        $resolver = new NextActionResolver();

        $domainA = $this->buildDomain(domainId: 'dom-a', domainName: 'old.example', totalReports: 200, firstReportAt: '2026-04-23 09:00:00');
        $domainB = $this->buildDomain(domainId: 'dom-b', domainName: 'fresh.example', totalReports: 0, firstReportAt: null);

        $result = $resolver->resolve(
            domains: [$domainA, $domainB],
            verificationStatus: $this->buildStatus(
                domainId: 'dom-a',
                domainName: 'old.example',
                dmarcVerifiedAt: new \DateTimeImmutable('-30 days'),
                firstReportAt: new \DateTimeImmutable('-29 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-04-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('old.example', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            domainRuaScenarios: [
                'dom-a' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
                'dom-b' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            ],
        );

        self::assertSame(NextAction::WaitForReports, $result->actionKey);
        self::assertStringContainsString('24-48 hours', $result->description);
    }

    #[Test]
    public function multiDomainTeamAllPointsAtSendveryWithReportsResolvesAllHealthy(): void
    {
        // TASK-129: when every PointsAtSendvery domain has at least one
        // report on file, the team has truly settled into the healthy
        // steady state and AllHealthy is correct.
        $resolver = new NextActionResolver();

        $domainA = $this->buildDomain(domainId: 'dom-a', domainName: 'a.example', totalReports: 200, firstReportAt: '2026-04-23 09:00:00');
        $domainB = $this->buildDomain(domainId: 'dom-b', domainName: 'b.example', totalReports: 80, firstReportAt: '2026-05-01 14:30:00');

        $result = $resolver->resolve(
            domains: [$domainA, $domainB],
            verificationStatus: $this->buildStatus(
                domainId: 'dom-a',
                domainName: 'a.example',
                dmarcVerifiedAt: new \DateTimeImmutable('-30 days'),
                firstReportAt: new \DateTimeImmutable('-29 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('2026-04-22 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('a.example', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            domainRuaScenarios: [
                'dom-a' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
                'dom-b' => new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
            ],
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
    }

    private function buildDomain(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        int $totalReports = 0,
        float $passRate = 100.0,
        ?string $firstReportAt = null,
    ): DomainOverviewResult {
        return new DomainOverviewResult(
            domainId: $domainId,
            domainName: $domainName,
            totalReports: $totalReports,
            latestReportDate: null,
            passRate: $passRate,
            teamId: 'team-id',
            teamName: 'Team',
            dmarcVerifiedAt: null,
            firstReportAt: $firstReportAt,
        );
    }

    private function buildStatus(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?\DateTimeImmutable $firstReportAt = null,
        int $consecutiveDmarcFailures = 0,
    ): DomainVerificationStatusResult {
        return new DomainVerificationStatusResult(
            domainId: $domainId,
            domainName: $domainName,
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: $dmarcVerifiedAt,
            firstReportAt: $firstReportAt,
            consecutiveDmarcFailures: $consecutiveDmarcFailures,
        );
    }

    private function buildIngestionPath(string $domainName, IngestionPath $path): DomainIngestionMatrixResult
    {
        return new DomainIngestionMatrixResult(
            domainId: 'dom-'.$domainName,
            domainName: $domainName,
            path: $path,
            lastReportAt: null,
            mailboxId: null,
            mailboxHost: null,
            mailboxPort: null,
        );
    }
}

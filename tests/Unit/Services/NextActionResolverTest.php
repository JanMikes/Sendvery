<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DomainIngestionMatrixResult;
use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Services\NextActionResolver;
use App\Value\DomainVerificationSeverity;
use App\Value\IngestionPath;
use App\Value\NextAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NextActionResolverTest extends TestCase
{
    #[Test]
    public function resolveAddDomainWhenNoDomains(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [],
            verificationStatus: null,
            verificationSeverity: null,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: null,
            ingestionPaths: [],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::AddDomain, $result->actionKey);
        self::assertSame('Add your first domain', $result->title);
        self::assertSame('dashboard_domain_add', $result->ctaRoute);
        self::assertSame([], $result->ctaRouteParams);
        self::assertSame('error', $result->severity);
        self::assertSame('Add domain', $result->ctaLabel);
    }

    #[Test]
    public function resolveVerifyDnsWhenDmarcNeverVerified(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com')],
            verificationStatus: $this->buildStatus(domainName: 'example.com', dmarcVerifiedAt: null),
            verificationSeverity: DomainVerificationSeverity::Critical,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-2 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::VerifyDns, $result->actionKey);
        self::assertSame('dashboard_domain_reverify', $result->ctaRoute);
        self::assertSame('error', $result->severity);
    }

    #[Test]
    public function resolveVerifyDnsWhenDmarcGoneMissing(): void
    {
        // Sustained failure outside the settling window — DomainVerificationEvaluator
        // produces Critical here, so NextActionResolver should pick VerifyDns even
        // though DMARC was once verified.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com')],
            verificationStatus: $this->buildStatus(
                domainName: 'example.com',
                dmarcVerifiedAt: new \DateTimeImmutable('-30 days'),
                consecutiveDmarcFailures: 3,
            ),
            verificationSeverity: DomainVerificationSeverity::Critical,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: true,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-30 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::VerifyDns, $result->actionKey);
    }

    #[Test]
    public function resolveVerifyDnsWinsOverAlerts(): void
    {
        // DMARC Critical wins over unread alerts — alerts are noise without DMARC.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com')],
            verificationStatus: $this->buildStatus(domainName: 'example.com', dmarcVerifiedAt: null),
            verificationSeverity: DomainVerificationSeverity::Critical,
            unreadCriticalAlertCount: 5,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-2 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::VerifyDns, $result->actionKey);
    }

    #[Test]
    public function resolveWaitForReportsWhenDmarcPublishedButNoReports(): void
    {
        // DMARC published > 48h ago, no reports yet → Warning severity from evaluator.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com')],
            verificationStatus: $this->buildStatus(
                domainName: 'example.com',
                dmarcVerifiedAt: new \DateTimeImmutable('-3 days'),
                firstReportAt: null,
            ),
            verificationSeverity: DomainVerificationSeverity::Warning,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-3 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::WaitForReports, $result->actionKey);
        self::assertSame('dashboard_dns_health', $result->ctaRoute);
        self::assertSame('warning', $result->severity);
    }

    #[Test]
    public function resolveWaitForReportsDescriptionContainsReportAddress(): void
    {
        // TASK-091: the WaitForReports copy must name the `reports@` address
        // so users know where their providers should be sending reports.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com')],
            verificationStatus: $this->buildStatus(
                domainName: 'example.com',
                dmarcVerifiedAt: new \DateTimeImmutable('-3 days'),
                firstReportAt: null,
            ),
            verificationSeverity: DomainVerificationSeverity::Warning,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@example-host.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-3 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertStringContainsString('reports@example-host.com', $result->description);
    }

    #[Test]
    public function resolveWaitForReportsWhenSettlingWindowActive(): void
    {
        // DMARC verified < 24h ago with a transient failure → Info severity.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com')],
            verificationStatus: $this->buildStatus(
                domainName: 'example.com',
                dmarcVerifiedAt: new \DateTimeImmutable('-2 hours'),
                consecutiveDmarcFailures: 1,
            ),
            verificationSeverity: DomainVerificationSeverity::Info,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-2 hours'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::WaitForReports, $result->actionKey);
    }

    #[Test]
    public function resolveWaitForReportsWinsOverConnectMailbox(): void
    {
        // Even with no mailbox and no reports, we don't push ConnectMailbox while
        // DMARC isn't fully settled — finish setup first.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'example.com', totalReports: 0)],
            verificationStatus: $this->buildStatus(
                domainName: 'example.com',
                dmarcVerifiedAt: new \DateTimeImmutable('-3 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Warning,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-3 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::WaitForReports, $result->actionKey);
    }

    #[Test]
    public function resolveReviewAlertsWhenCriticalUnread(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 100)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 3,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Dns)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::ReviewAlerts, $result->actionKey);
        self::assertSame('dashboard_alerts', $result->ctaRoute);
        self::assertSame('error', $result->severity);
    }

    #[Test]
    public function resolvePublishRuaRecordWhenNoReportsAndWithinSevenDays(): void
    {
        // TASK-091: brand-new team with a verified domain but no central-inbox
        // reports yet. Within the 7-day grace window, no dismissal → the
        // recommended next-step is PublishRuaRecord (DNS-first), with the
        // mailbox-fallback link rendered as a secondary CTA.
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
        );

        self::assertSame(NextAction::PublishRuaRecord, $result->actionKey);
        self::assertSame('dashboard_dns_health', $result->ctaRoute);
        self::assertSame('info', $result->severity);
        self::assertStringContainsString('reports@sendvery.com', $result->description);
        self::assertSame('Prefer to connect a mailbox instead? (fallback)', $result->secondaryCtaLabel);
        self::assertSame('dashboard_mailbox_add', $result->secondaryCtaRoute);
    }

    #[Test]
    public function resolveConnectMailboxAfterSevenDaysWithNoCentralReports(): void
    {
        // TASK-091: oldest domain was added 8 days ago, no central-inbox
        // reports → the 7-day grace expires and the demoted fallback
        // "Connect a mailbox" branch takes over.
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
            earliestDomainAddedAt: new \DateTimeImmutable('2026-05-16 12:00:00'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::ConnectMailbox, $result->actionKey);
        self::assertSame('dashboard_mailbox_add', $result->ctaRoute);
        self::assertStringContainsString('fallback', $result->title);
        self::assertStringNotContainsString('in addition to', $result->description);
    }

    #[Test]
    public function resolveConnectMailboxWhenDismissed(): void
    {
        // TASK-091: even within the 7-day window, an explicit dismissal
        // promotes the demoted fallback so the user sees the mailbox
        // option as the recommended next step.
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
            ingestionRecommendationDismissedAt: new \DateTimeImmutable('2026-05-23 14:00:00'),
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::ConnectMailbox, $result->actionKey);
        self::assertNull($result->secondaryCtaRoute);
    }

    #[Test]
    public function resolveAllHealthyWhenCentralReportsExist(): void
    {
        // TASK-091: when any domain has IngestionPath::Dns (central inbox
        // delivering reports), neither PublishRuaRecord nor ConnectMailbox
        // fires — the team is healthy on the recommended path.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 100)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Dns)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
    }

    #[Test]
    public function resolveAllHealthyWhenMixedIngestionPathPresent(): void
    {
        // A Mixed ingestion path also indicates central inbox is active
        // (it's the "both DNS and mailbox" misconfig case) → AllHealthy
        // takes over, the user is nudged about the misconfig elsewhere.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 50)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: true,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Mixed)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
    }

    #[Test]
    public function resolveConnectMailboxWhenNoMailboxAndNoReports(): void
    {
        // Regression coverage from the pre-TASK-091 behaviour: when the
        // 7-day fallback has passed and no central reports exist, we still
        // pick ConnectMailbox (the demoted fallback variant).
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
            earliestDomainAddedAt: new \DateTimeImmutable('-8 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::ConnectMailbox, $result->actionKey);
        self::assertSame('dashboard_mailbox_add', $result->ctaRoute);
        self::assertSame('info', $result->severity);
    }

    #[Test]
    public function resolveAllHealthyWhenAnyDomainHasCentralReports(): void
    {
        // Mixed multi-domain state: one domain still has zero reports but
        // another is already receiving them via the central inbox (Dns path).
        // The "connect a mailbox" nudge is intentionally suppressed once
        // any domain is on the DNS path — partial coverage still means
        // the report pipeline is working for this team.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [
                $this->buildDomain(domainName: 'a.example.com', totalReports: 0),
                $this->buildDomain(domainName: 'b.example.com', totalReports: 50),
            ],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [
                $this->buildIngestionPath('a.example.com', IngestionPath::None),
                $this->buildIngestionPath('b.example.com', IngestionPath::Dns),
            ],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
    }

    #[Test]
    public function resolveAllHealthyWhenDomainHasReports(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 100)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: true,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Dns)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
        self::assertSame('success', $result->severity);
        self::assertSame('dashboard_reports', $result->ctaRoute);
    }

    #[Test]
    public function resolveAllHealthyDoesNotRequireMailbox(): void
    {
        // Reports flowing via the central inbox → no personal mailbox needed.
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 50)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Dns)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
    }

    #[Test]
    public function resolveVerifyDnsContainsDomainNameInTitle(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainName: 'acme.example')],
            verificationStatus: $this->buildStatus(domainName: 'acme.example', dmarcVerifiedAt: null),
            verificationSeverity: DomainVerificationSeverity::Critical,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-2 days'),
            ingestionPaths: [$this->buildIngestionPath('acme.example', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertStringContainsString('acme.example', $result->title);
        self::assertStringContainsString('acme.example', $result->description);
    }

    #[Test]
    public function resolveVerifyDnsCtaRouteParamsContainDomainId(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(domainId: 'dom-uuid', domainName: 'example.com')],
            verificationStatus: $this->buildStatus(
                domainId: 'dom-uuid',
                domainName: 'example.com',
                dmarcVerifiedAt: null,
            ),
            verificationSeverity: DomainVerificationSeverity::Critical,
            unreadCriticalAlertCount: 0,
            quarantineCount: 0,
            hasMailbox: false,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-2 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::None)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame(['id' => 'dom-uuid'], $result->ctaRouteParams);
    }

    #[Test]
    public function resolveReviewAlertsCountAppearsInTitle(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 100)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 3,
            quarantineCount: 0,
            hasMailbox: true,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Dns)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame('Review 3 critical alerts', $result->title);
    }

    #[Test]
    public function resolveReviewAlertsUsesSingularForOne(): void
    {
        $resolver = new NextActionResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(totalReports: 100)],
            verificationStatus: $this->buildStatus(
                dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
                firstReportAt: new \DateTimeImmutable('-9 days'),
            ),
            verificationSeverity: DomainVerificationSeverity::Ok,
            unreadCriticalAlertCount: 1,
            quarantineCount: 0,
            hasMailbox: true,
            reportAddress: 'reports@sendvery.com',
            earliestDomainAddedAt: new \DateTimeImmutable('-10 days'),
            ingestionPaths: [$this->buildIngestionPath('example.com', IngestionPath::Dns)],
            ingestionRecommendationDismissedAt: null,
            now: new \DateTimeImmutable('2026-05-24 12:00:00'),
        );

        self::assertSame('Review 1 critical alert', $result->title);
    }

    private function buildDomain(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        int $totalReports = 0,
        float $passRate = 100.0,
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

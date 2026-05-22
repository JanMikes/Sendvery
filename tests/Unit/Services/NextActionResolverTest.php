<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Services\NextActionResolver;
use App\Value\DomainVerificationSeverity;
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
        );

        self::assertSame(NextAction::WaitForReports, $result->actionKey);
        self::assertSame('dashboard_dns_health', $result->ctaRoute);
        self::assertSame('warning', $result->severity);
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
        );

        self::assertSame(NextAction::ReviewAlerts, $result->actionKey);
        self::assertSame('dashboard_alerts', $result->ctaRoute);
        self::assertSame('error', $result->severity);
    }

    #[Test]
    public function resolveConnectMailboxWhenNoMailboxAndNoReports(): void
    {
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
        );

        self::assertSame(NextAction::ConnectMailbox, $result->actionKey);
        self::assertSame('dashboard_mailbox_add', $result->ctaRoute);
        self::assertSame('info', $result->severity);
    }

    #[Test]
    public function resolveConnectMailboxSuppressedWhenAnyDomainHasReports(): void
    {
        // Mixed multi-domain state: one domain still has zero reports but
        // another is already receiving them via the central inbox. The
        // "connect a mailbox" nudge is intentionally suppressed unless ALL
        // domains are dry — partial coverage still means the report pipeline
        // is working for this team.
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
}

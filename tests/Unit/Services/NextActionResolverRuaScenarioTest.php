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
        // Scenario (b): DMARC already routes to Sendvery. No CTA needs to
        // fire — fall through to AllHealthy. The fact reports haven't
        // landed yet is a propagation issue, not a missing-setup one.
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
            headlineDomainRuaScenario: new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
        );

        self::assertSame(NextAction::AllHealthy, $result->actionKey);
        self::assertSame('success', $result->severity);
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
        self::assertSame('dashboard_dns_health', $result->secondaryCtaRoute);
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

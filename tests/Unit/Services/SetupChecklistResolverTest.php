<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\SetupChecklistResolver;
use App\Value\Dns\RuaScenario;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetupChecklistResolverTest extends TestCase
{
    #[Test]
    public function resolveAllStepsIncompleteForBrandNewTeam(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 0,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertSame(3, $result->totalCount);
        self::assertSame(0, $result->completedCount);
        self::assertTrue($result->isVisible);
        self::assertFalse($result->isFullyComplete);
        self::assertFalse($result->steps[0]->isComplete);
        self::assertFalse($result->steps[1]->isComplete);
        self::assertFalse($result->steps[2]->isComplete);
    }

    #[Test]
    public function resolveDomainStepCompletesWhenAtLeastOneDomain(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertTrue($result->steps[0]->isComplete);
        self::assertFalse($result->steps[1]->isComplete);
        self::assertFalse($result->steps[2]->isComplete);
        self::assertSame(1, $result->completedCount);
        self::assertTrue($result->isVisible);
    }

    #[Test]
    public function resolveDmarcStepCompletesWhenAnyDomainVerified(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: true,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertTrue($result->steps[1]->isComplete);
        self::assertSame(2, $result->completedCount);
        self::assertTrue($result->isVisible);
    }

    #[Test]
    public function resolveReceiveReportsStepCompletesViaFirstReport(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: true,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertTrue($result->steps[2]->isComplete);
    }

    #[Test]
    public function resolveReceiveReportsStepCompletesViaMailbox(): void
    {
        // Mailbox alone counts: even without a parsed report yet, having a
        // dedicated IMAP mailbox connected means the user has finished the
        // "report ingestion" leg of setup.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: true,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertTrue($result->steps[2]->isComplete);
    }

    #[Test]
    public function resolveMailboxAloneDoesNotCompleteDomainStep(): void
    {
        // Edge case: a user wired up a mailbox somehow without adding a
        // monitored domain. The first step is still owed.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 0,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: true,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertFalse($result->steps[0]->isComplete);
        self::assertTrue($result->steps[2]->isComplete);
    }

    #[Test]
    public function resolveDismissedHidesChecklistWhenNoRegression(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: new \DateTimeImmutable('-1 day'),
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertFalse($result->isVisible);
        self::assertSame(1, $result->completedCount);
    }

    #[Test]
    public function resolveDismissedOverriddenByDmarcRegression(): void
    {
        // The team dismissed the checklist when DMARC was verified — but
        // then the record went missing. We re-surface the checklist so
        // the user can act, without ever clearing the dismissal column.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: true,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: new \DateTimeImmutable('-7 days'),
            hasDmarcRegression: true,
            headlineDomainRuaScenario: null,
        );

        self::assertTrue($result->isVisible);
    }

    #[Test]
    public function resolveRegressionWithoutEverVerifiedDoesNotOverride(): void
    {
        // A team that never verified DMARC and dismissed the checklist
        // shouldn't pop back open on the first failed nightly DNS check —
        // that's just the initial unverified state.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: new \DateTimeImmutable('-1 day'),
            hasDmarcRegression: true,
            headlineDomainRuaScenario: null,
        );

        self::assertFalse($result->isVisible);
    }

    #[Test]
    public function resolveFullyCompleteIsNotVisible(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 2,
            anyDomainHasDmarcVerified: true,
            anyDomainHasFirstReport: true,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertTrue($result->isFullyComplete);
        self::assertFalse($result->isVisible);
        self::assertSame(3, $result->completedCount);
    }

    #[Test]
    public function resolveFullyCompleteIgnoresRegressionFlag(): void
    {
        // A regression flag set together with everything-complete is a
        // contradictory input; we still hide the checklist because the
        // user has nothing to do here.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: true,
            anyDomainHasFirstReport: true,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: true,
            headlineDomainRuaScenario: null,
        );

        self::assertFalse($result->isVisible);
        self::assertTrue($result->isFullyComplete);
    }

    #[Test]
    public function resolveStepCountAlwaysThree(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 0,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertCount(3, $result->steps);
        self::assertSame(3, $result->totalCount);
    }

    #[Test]
    public function resolveStepIdsAreStable(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 0,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertSame('add_domain', $result->steps[0]->id);
        self::assertSame('publish_dmarc', $result->steps[1]->id);
        self::assertSame('receive_reports', $result->steps[2]->id);
    }

    #[Test]
    public function resolveStepRouteNamesAreCorrect(): void
    {
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 0,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        self::assertSame('dashboard_domain_add', $result->steps[0]->actionRoute);
        self::assertSame('dashboard_domains', $result->steps[1]->actionRoute);
        self::assertSame('dashboard_dns_health', $result->steps[2]->actionRoute);
        self::assertSame([], $result->steps[0]->actionRouteParams);
        self::assertSame([], $result->steps[1]->actionRouteParams);
        self::assertSame([], $result->steps[2]->actionRouteParams);
    }

    #[Test]
    public function resolveReceiveReportsCopyForPointsAtSendverySuppressesMailboxAlternative(): void
    {
        // TASK-128: when the user's published DMARC record already routes
        // reports at Sendvery, the alternative "or connect a mailbox" line
        // contradicts the correctly-configured state. Step 3 swaps to the
        // passive "reports flow in automatically" wording and a DNS-check
        // CTA — never a mailbox CTA.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: true,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: RuaScenario::PointsAtSendvery,
        );

        $receiveReports = $result->steps[2];
        self::assertSame('receive_reports', $receiveReports->id);
        self::assertStringContainsString('Reports flow in automatically', $receiveReports->description);
        self::assertStringContainsString('24-48 hours', $receiveReports->description);
        self::assertStringNotContainsString('Connect a mailbox', $receiveReports->description);
        self::assertStringNotContainsString('Connect a mailbox', $receiveReports->actionLabel);
        self::assertSame('Check DNS setup', $receiveReports->actionLabel);
        self::assertSame('dashboard_dns_health', $receiveReports->actionRoute);
    }

    #[Test]
    public function resolveReceiveReportsCopyForPointsAtExternalRecommendsConnectingThatInbox(): void
    {
        // TASK-128 / TASK-100 scenario (c): DMARC publishes rua= against
        // an inbox the team owns elsewhere. Step 3 mirrors NextAction's
        // ConnectExternalMailbox copy — recommend connecting THAT inbox
        // (or repointing DMARC at Sendvery), never the generic mailbox
        // fallback line aimed at NoRecord users.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: true,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: RuaScenario::PointsAtExternal,
        );

        $receiveReports = $result->steps[2];
        self::assertStringContainsString('inbox you own', $receiveReports->description);
        self::assertStringNotContainsString('Connect a mailbox if you prefer', $receiveReports->description);
        self::assertSame('Connect that inbox', $receiveReports->actionLabel);
        self::assertSame('dashboard_mailbox_add', $receiveReports->actionRoute);
    }

    #[Test]
    public function resolveReceiveReportsCopyForNoRecordKeepsGenericFallback(): void
    {
        // NoRecord: the user hasn't published DMARC yet, so the "publish +
        // alternatively connect a mailbox" framing is still accurate. Keep
        // the original copy + CTA.
        $resolver = new SetupChecklistResolver();

        $result = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: RuaScenario::NoRecord,
        );

        $receiveReports = $result->steps[2];
        self::assertStringContainsString('Connect a mailbox if you prefer', $receiveReports->description);
        self::assertSame('Do it', $receiveReports->actionLabel);
        self::assertSame('dashboard_dns_health', $receiveReports->actionRoute);
    }

    #[Test]
    public function resolveReceiveReportsCopyForNullScenarioMatchesNoRecordBranch(): void
    {
        // Backwards-compat for callers that haven't been migrated to pass
        // a scenario yet (and for the "no headline domain at all" case on
        // a fresh team). Null behaves identically to NoRecord — the same
        // generic copy renders so nothing regresses for those callers.
        $resolver = new SetupChecklistResolver();

        $withNull = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: null,
        );

        $withNoRecord = $resolver->resolve(
            domainCount: 1,
            anyDomainHasDmarcVerified: false,
            anyDomainHasFirstReport: false,
            hasMailbox: false,
            dismissedAt: null,
            hasDmarcRegression: false,
            headlineDomainRuaScenario: RuaScenario::NoRecord,
        );

        self::assertSame($withNull->steps[2]->description, $withNoRecord->steps[2]->description);
        self::assertSame($withNull->steps[2]->actionLabel, $withNoRecord->steps[2]->actionLabel);
        self::assertSame($withNull->steps[2]->actionRoute, $withNoRecord->steps[2]->actionRoute);
    }

    #[Test]
    public function resolveReceiveReportsScenarioDoesNotChangeCompletionLogic(): void
    {
        // The scenario only affects copy/CTA — once the user has actually
        // received a report (or connected a mailbox), the step is complete
        // regardless of which scenario branch rendered.
        $resolver = new SetupChecklistResolver();

        foreach ([RuaScenario::PointsAtSendvery, RuaScenario::PointsAtExternal, RuaScenario::NoRecord, null] as $scenario) {
            $result = $resolver->resolve(
                domainCount: 1,
                anyDomainHasDmarcVerified: true,
                anyDomainHasFirstReport: true,
                hasMailbox: false,
                dismissedAt: null,
                hasDmarcRegression: false,
                headlineDomainRuaScenario: $scenario,
            );

            self::assertTrue(
                $result->steps[2]->isComplete,
                sprintf('Expected receive_reports complete for scenario %s', null === $scenario ? 'null' : $scenario->value),
            );
        }
    }
}

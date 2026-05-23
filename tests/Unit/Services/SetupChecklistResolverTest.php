<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\SetupChecklistResolver;
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
        );

        self::assertSame('dashboard_domain_add', $result->steps[0]->actionRoute);
        self::assertSame('dashboard_domains', $result->steps[1]->actionRoute);
        self::assertSame('dashboard_dns_health', $result->steps[2]->actionRoute);
        self::assertSame([], $result->steps[0]->actionRouteParams);
        self::assertSame([], $result->steps[1]->actionRouteParams);
        self::assertSame([], $result->steps[2]->actionRouteParams);
    }
}

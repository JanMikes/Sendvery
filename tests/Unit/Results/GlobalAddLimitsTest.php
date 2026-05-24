<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\GlobalAddLimits;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GlobalAddLimitsTest extends TestCase
{
    #[Test]
    public function nullFactoryReturnsAllPermissiveZeroState(): void
    {
        $limits = GlobalAddLimits::null();

        self::assertFalse($limits->canAddDomain);
        self::assertSame(0, $limits->domainCount);
        self::assertSame(0, $limits->maxDomains);
        // canAddMailbox is always true — even in the null state mailboxes are unbounded.
        self::assertTrue($limits->canAddMailbox);
        self::assertFalse($limits->isTeamManager);
        self::assertFalse($limits->canAddTeamMember);
        self::assertSame(0, $limits->effectiveMemberCount);
        self::assertSame(0, $limits->maxMembers);
        self::assertFalse($limits->canInvite());
    }

    #[Test]
    public function domainLimitDisplayReturnsInfinitySymbolForUnlimitedPlan(): void
    {
        $limits = $this->limits(maxDomains: PHP_INT_MAX);

        self::assertSame('∞', $limits->domainLimitDisplay());
    }

    #[Test]
    public function domainLimitDisplayReturnsNumericStringForCappedPlan(): void
    {
        $limits = $this->limits(maxDomains: 5);

        self::assertSame('5', $limits->domainLimitDisplay());
    }

    #[Test]
    public function memberLimitDisplayReturnsInfinitySymbolForUnlimitedPlan(): void
    {
        $limits = $this->limits(maxMembers: PHP_INT_MAX);

        self::assertSame('∞', $limits->memberLimitDisplay());
    }

    #[Test]
    public function memberLimitDisplayReturnsNumericStringForCappedPlan(): void
    {
        $limits = $this->limits(maxMembers: 10);

        self::assertSame('10', $limits->memberLimitDisplay());
    }

    #[Test]
    public function canInviteIsTrueOnlyWhenBothManagerAndUnderSeatCap(): void
    {
        // Manager + headroom → true.
        self::assertTrue($this->limits(isTeamManager: true, canAddTeamMember: true)->canInvite());
        // Manager but at cap → false.
        self::assertFalse($this->limits(isTeamManager: true, canAddTeamMember: false)->canInvite());
        // Non-manager with headroom → false (role overrides seat availability).
        self::assertFalse($this->limits(isTeamManager: false, canAddTeamMember: true)->canInvite());
        // Non-manager at cap → false.
        self::assertFalse($this->limits(isTeamManager: false, canAddTeamMember: false)->canInvite());
    }

    private function limits(
        bool $canAddDomain = true,
        int $domainCount = 0,
        int $maxDomains = 5,
        bool $canAddMailbox = true,
        bool $isTeamManager = true,
        bool $canAddTeamMember = true,
        int $effectiveMemberCount = 1,
        int $maxMembers = 3,
    ): GlobalAddLimits {
        return new GlobalAddLimits(
            canAddDomain: $canAddDomain,
            domainCount: $domainCount,
            maxDomains: $maxDomains,
            canAddMailbox: $canAddMailbox,
            isTeamManager: $isTeamManager,
            canAddTeamMember: $canAddTeamMember,
            effectiveMemberCount: $effectiveMemberCount,
            maxMembers: $maxMembers,
        );
    }
}

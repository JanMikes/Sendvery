<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Stripe;

use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\TestCase;

final class PlanLimitsTest extends TestCase
{
    private PlanLimits $planLimits;

    protected function setUp(): void
    {
        $this->planLimits = new PlanLimits();
    }

    public function testMaxDomainsForFreePlan(): void
    {
        self::assertSame(1, $this->planLimits->getMaxDomains(SubscriptionPlan::Free));
    }

    public function testMaxDomainsForPersonalPlan(): void
    {
        self::assertSame(5, $this->planLimits->getMaxDomains(SubscriptionPlan::Personal));
    }

    public function testMaxDomainsForTeamPlan(): void
    {
        self::assertSame(50, $this->planLimits->getMaxDomains(SubscriptionPlan::Team));
    }

    public function testMaxTeamMembersForFreePlan(): void
    {
        self::assertSame(1, $this->planLimits->getMaxTeamMembers(SubscriptionPlan::Free));
    }

    public function testMaxTeamMembersForPersonalPlan(): void
    {
        self::assertSame(1, $this->planLimits->getMaxTeamMembers(SubscriptionPlan::Personal));
    }

    public function testMaxTeamMembersForTeamPlan(): void
    {
        self::assertSame(10, $this->planLimits->getMaxTeamMembers(SubscriptionPlan::Team));
    }

    public function testFreeHasDigest(): void
    {
        self::assertTrue($this->planLimits->hasFeature(SubscriptionPlan::Free, 'digest'));
    }

    public function testFreeDoesNotHaveAlerts(): void
    {
        self::assertFalse($this->planLimits->hasFeature(SubscriptionPlan::Free, 'alerts'));
    }

    public function testFreeDoesNotHaveDnsMonitoring(): void
    {
        self::assertFalse($this->planLimits->hasFeature(SubscriptionPlan::Free, 'dns_monitoring'));
    }

    public function testPersonalHasAlerts(): void
    {
        self::assertTrue($this->planLimits->hasFeature(SubscriptionPlan::Personal, 'alerts'));
    }

    public function testPersonalDoesNotHaveApiAccess(): void
    {
        self::assertFalse($this->planLimits->hasFeature(SubscriptionPlan::Personal, 'api_access'));
    }

    public function testPersonalDoesNotHaveAiInsights(): void
    {
        self::assertFalse($this->planLimits->hasFeature(SubscriptionPlan::Personal, 'ai_insights'));
    }

    public function testTeamHasApiAccess(): void
    {
        self::assertTrue($this->planLimits->hasFeature(SubscriptionPlan::Team, 'api_access'));
    }

    public function testTeamHasAiInsights(): void
    {
        self::assertTrue($this->planLimits->hasFeature(SubscriptionPlan::Team, 'ai_insights'));
    }

    public function testTeamHasBlacklistMonitoring(): void
    {
        self::assertTrue($this->planLimits->hasFeature(SubscriptionPlan::Team, 'blacklist_monitoring'));
    }

    public function testUnknownFeatureReturnsFalse(): void
    {
        self::assertFalse($this->planLimits->hasFeature(SubscriptionPlan::Team, 'nonexistent_feature'));
    }
}

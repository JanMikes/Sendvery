<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Stripe;

use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Canonical pinned matrix per docs/05-monetization.md. When the doc changes,
 * this test changes — and a failing test forces a deliberate review.
 */
final class PlanLimitsTest extends TestCase
{
    private PlanLimits $planLimits;

    protected function setUp(): void
    {
        $this->planLimits = new PlanLimits();
    }

    // ─── Max domains ──────────────────────────────────────────────────────

    #[DataProvider('maxDomainsProvider')]
    public function testGetMaxDomains(SubscriptionPlan $plan, int $expected): void
    {
        self::assertSame($expected, $this->planLimits->getMaxDomains($plan));
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: int}> */
    public static function maxDomainsProvider(): iterable
    {
        yield 'free → 1' => [SubscriptionPlan::Free, 1];
        yield 'personal → 5' => [SubscriptionPlan::Personal, 5];
        yield 'personal_ai → 5' => [SubscriptionPlan::PersonalAi, 5];
        yield 'pro → 20' => [SubscriptionPlan::Pro, 20];
        yield 'pro_ai → 20' => [SubscriptionPlan::ProAi, 20];
        yield 'business → 50' => [SubscriptionPlan::Business, 50];
        yield 'business_ai → 50' => [SubscriptionPlan::BusinessAi, 50];
        yield 'unlimited → PHP_INT_MAX' => [SubscriptionPlan::Unlimited, PHP_INT_MAX];
    }

    // ─── Max team members ─────────────────────────────────────────────────

    #[DataProvider('maxTeamMembersProvider')]
    public function testGetMaxTeamMembers(SubscriptionPlan $plan, int $expected): void
    {
        self::assertSame($expected, $this->planLimits->getMaxTeamMembers($plan));
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: int}> */
    public static function maxTeamMembersProvider(): iterable
    {
        yield 'free → 1' => [SubscriptionPlan::Free, 1];
        yield 'personal → 1' => [SubscriptionPlan::Personal, 1];
        yield 'personal_ai → 1' => [SubscriptionPlan::PersonalAi, 1];
        yield 'pro → 3' => [SubscriptionPlan::Pro, 3];
        yield 'pro_ai → 3' => [SubscriptionPlan::ProAi, 3];
        yield 'business → 10' => [SubscriptionPlan::Business, 10];
        yield 'business_ai → 10' => [SubscriptionPlan::BusinessAi, 10];
        yield 'unlimited → PHP_INT_MAX' => [SubscriptionPlan::Unlimited, PHP_INT_MAX];
    }

    // ─── Max reports/mo ───────────────────────────────────────────────────

    #[DataProvider('maxReportsPerMonthProvider')]
    public function testGetMaxReportsPerMonth(SubscriptionPlan $plan, int $expected): void
    {
        self::assertSame($expected, $this->planLimits->getMaxReportsPerMonth($plan));
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: int}> */
    public static function maxReportsPerMonthProvider(): iterable
    {
        yield 'free → 100' => [SubscriptionPlan::Free, 100];
        yield 'personal → 1k' => [SubscriptionPlan::Personal, 1_000];
        yield 'personal_ai → 1k' => [SubscriptionPlan::PersonalAi, 1_000];
        yield 'pro → 10k' => [SubscriptionPlan::Pro, 10_000];
        yield 'pro_ai → 10k' => [SubscriptionPlan::ProAi, 10_000];
        yield 'business → 50k' => [SubscriptionPlan::Business, 50_000];
        yield 'business_ai → 50k' => [SubscriptionPlan::BusinessAi, 50_000];
        yield 'unlimited → PHP_INT_MAX' => [SubscriptionPlan::Unlimited, PHP_INT_MAX];
    }

    // ─── Retention days ───────────────────────────────────────────────────

    #[DataProvider('retentionDaysProvider')]
    public function testGetRetentionDays(SubscriptionPlan $plan, ?int $expected): void
    {
        self::assertSame($expected, $this->planLimits->getRetentionDays($plan));
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: ?int}> */
    public static function retentionDaysProvider(): iterable
    {
        yield 'free → 30d' => [SubscriptionPlan::Free, 30];
        yield 'personal → 1y' => [SubscriptionPlan::Personal, 365];
        yield 'personal_ai → 1y' => [SubscriptionPlan::PersonalAi, 365];
        yield 'pro → 2y' => [SubscriptionPlan::Pro, 730];
        yield 'pro_ai → 2y' => [SubscriptionPlan::ProAi, 730];
        yield 'business → unlimited' => [SubscriptionPlan::Business, null];
        yield 'business_ai → unlimited' => [SubscriptionPlan::BusinessAi, null];
        yield 'unlimited → unlimited' => [SubscriptionPlan::Unlimited, null];
    }

    // ─── On-demand AI quota ───────────────────────────────────────────────

    #[DataProvider('onDemandAiQuotaProvider')]
    public function testGetOnDemandAiQuota(SubscriptionPlan $plan, int $expected): void
    {
        self::assertSame($expected, $this->planLimits->getOnDemandAiQuota($plan));
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: int}> */
    public static function onDemandAiQuotaProvider(): iterable
    {
        yield 'free → 0' => [SubscriptionPlan::Free, 0];
        yield 'personal base → 0' => [SubscriptionPlan::Personal, 0];
        yield 'personal_ai → 50' => [SubscriptionPlan::PersonalAi, 50];
        yield 'pro base → 0' => [SubscriptionPlan::Pro, 0];
        yield 'pro_ai → 200' => [SubscriptionPlan::ProAi, 200];
        yield 'business base → 0' => [SubscriptionPlan::Business, 0];
        yield 'business_ai → 500' => [SubscriptionPlan::BusinessAi, 500];
        yield 'unlimited → PHP_INT_MAX' => [SubscriptionPlan::Unlimited, PHP_INT_MAX];
    }

    // ─── Features ─────────────────────────────────────────────────────────

    #[DataProvider('featureMatrixProvider')]
    public function testHasFeature(SubscriptionPlan $plan, string $feature, bool $expected): void
    {
        self::assertSame(
            $expected,
            $this->planLimits->hasFeature($plan, $feature),
            sprintf('Plan %s feature %s expected %s', $plan->value, $feature, $expected ? 'true' : 'false'),
        );
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: string, 2: bool}> */
    public static function featureMatrixProvider(): iterable
    {
        // Digest: everyone (including Free) gets the digest.
        yield 'free has digest' => [SubscriptionPlan::Free, 'digest', true];
        yield 'personal has digest' => [SubscriptionPlan::Personal, 'digest', true];

        // dns_monitoring / alerts / blacklist / sender_inventory / pdf_export: paid only.
        yield 'free no dns_monitoring' => [SubscriptionPlan::Free, 'dns_monitoring', false];
        yield 'free no alerts' => [SubscriptionPlan::Free, 'alerts', false];
        yield 'free no blacklist' => [SubscriptionPlan::Free, 'blacklist_monitoring', false];
        yield 'free no sender_inventory' => [SubscriptionPlan::Free, 'sender_inventory', false];
        yield 'free no pdf_export' => [SubscriptionPlan::Free, 'pdf_export', false];
        yield 'personal has alerts' => [SubscriptionPlan::Personal, 'alerts', true];
        yield 'personal has blacklist' => [SubscriptionPlan::Personal, 'blacklist_monitoring', true];
        yield 'business has all base features' => [SubscriptionPlan::Business, 'pdf_export', true];

        // api_access: Pro + Business + their AI variants.
        yield 'free no api' => [SubscriptionPlan::Free, 'api_access', false];
        yield 'personal no api' => [SubscriptionPlan::Personal, 'api_access', false];
        yield 'personal_ai no api' => [SubscriptionPlan::PersonalAi, 'api_access', false];
        yield 'pro has api' => [SubscriptionPlan::Pro, 'api_access', true];
        yield 'pro_ai has api' => [SubscriptionPlan::ProAi, 'api_access', true];
        yield 'business has api' => [SubscriptionPlan::Business, 'api_access', true];
        yield 'business_ai has api' => [SubscriptionPlan::BusinessAi, 'api_access', true];

        // ai_insights: only the *Ai variants (and Unlimited).
        yield 'free no AI' => [SubscriptionPlan::Free, 'ai_insights', false];
        yield 'personal no AI' => [SubscriptionPlan::Personal, 'ai_insights', false];
        yield 'personal_ai has AI' => [SubscriptionPlan::PersonalAi, 'ai_insights', true];
        yield 'pro no AI' => [SubscriptionPlan::Pro, 'ai_insights', false];
        yield 'pro_ai has AI' => [SubscriptionPlan::ProAi, 'ai_insights', true];
        yield 'business no AI' => [SubscriptionPlan::Business, 'ai_insights', false];
        yield 'business_ai has AI' => [SubscriptionPlan::BusinessAi, 'ai_insights', true];

        // white_label_pdf: Business + BusinessAi only.
        yield 'free no white_label' => [SubscriptionPlan::Free, 'white_label_pdf', false];
        yield 'personal no white_label' => [SubscriptionPlan::Personal, 'white_label_pdf', false];
        yield 'pro no white_label' => [SubscriptionPlan::Pro, 'white_label_pdf', false];
        yield 'business has white_label' => [SubscriptionPlan::Business, 'white_label_pdf', true];
        yield 'business_ai has white_label' => [SubscriptionPlan::BusinessAi, 'white_label_pdf', true];

        // unknown feature: false (Unlimited is a separate case below).
        yield 'unknown feature → false' => [SubscriptionPlan::Business, 'nonexistent_feature', false];
    }

    public function testUnlimitedHasAllKnownFeatures(): void
    {
        $features = ['dns_monitoring', 'alerts', 'digest', 'api_access', 'blacklist_monitoring', 'ai_insights', 'pdf_export', 'sender_inventory', 'white_label_pdf'];

        foreach ($features as $feature) {
            self::assertTrue(
                $this->planLimits->hasFeature(SubscriptionPlan::Unlimited, $feature),
                sprintf('Unlimited plan should have feature "%s"', $feature),
            );
        }
    }

    public function testUnlimitedReturnsTrueEvenForUnknownFeature(): void
    {
        self::assertTrue($this->planLimits->hasFeature(SubscriptionPlan::Unlimited, 'future_feature_we_havent_built_yet'));
    }
}

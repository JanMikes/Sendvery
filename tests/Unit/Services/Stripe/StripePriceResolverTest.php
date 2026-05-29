<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Stripe;

use App\Exceptions\AiNotYetPurchasable;
use App\Services\Stripe\StripePriceResolver;
use App\Value\BillingInterval;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripePriceResolverTest extends TestCase
{
    public function testProducesTheCanonicalLookupKeyForEveryCombination(): void
    {
        // These keys are the contract with Stripe: provisioning creates prices
        // with exactly these lookup keys (see docs/14-stripe-setup.md), and the
        // app resolves the runtime price ID from them. The same keys work in
        // sandbox and live, which is the whole point of keying on lookups.
        $resolver = new StripePriceResolver(aiPurchasable: true);

        self::assertSame('sendvery_personal_monthly', $resolver->getLookupKey(SubscriptionPlan::Personal, BillingInterval::Monthly));
        self::assertSame('sendvery_personal_annual', $resolver->getLookupKey(SubscriptionPlan::Personal, BillingInterval::Annual));
        self::assertSame('sendvery_personal_ai_monthly', $resolver->getLookupKey(SubscriptionPlan::PersonalAi, BillingInterval::Monthly));
        self::assertSame('sendvery_personal_ai_annual', $resolver->getLookupKey(SubscriptionPlan::PersonalAi, BillingInterval::Annual));
        self::assertSame('sendvery_pro_monthly', $resolver->getLookupKey(SubscriptionPlan::Pro, BillingInterval::Monthly));
        self::assertSame('sendvery_pro_annual', $resolver->getLookupKey(SubscriptionPlan::Pro, BillingInterval::Annual));
        self::assertSame('sendvery_pro_ai_monthly', $resolver->getLookupKey(SubscriptionPlan::ProAi, BillingInterval::Monthly));
        self::assertSame('sendvery_pro_ai_annual', $resolver->getLookupKey(SubscriptionPlan::ProAi, BillingInterval::Annual));
        self::assertSame('sendvery_business_monthly', $resolver->getLookupKey(SubscriptionPlan::Business, BillingInterval::Monthly));
        self::assertSame('sendvery_business_annual', $resolver->getLookupKey(SubscriptionPlan::Business, BillingInterval::Annual));
        self::assertSame('sendvery_business_ai_monthly', $resolver->getLookupKey(SubscriptionPlan::BusinessAi, BillingInterval::Monthly));
        self::assertSame('sendvery_business_ai_annual', $resolver->getLookupKey(SubscriptionPlan::BusinessAi, BillingInterval::Annual));
    }

    public function testFreePlanThrowsException(): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Free plan does not have a Stripe price.');
        $resolver->getLookupKey(SubscriptionPlan::Free, BillingInterval::Monthly);
    }

    public function testUnlimitedPlanThrowsException(): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unlimited plan is internal-only');
        $resolver->getLookupKey(SubscriptionPlan::Unlimited, BillingInterval::Annual);
    }

    #[DataProvider('aiVariantsProvider')]
    public function testAiVariantThrowsWhenNotPurchasable(SubscriptionPlan $plan): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: false);

        $this->expectException(AiNotYetPurchasable::class);

        try {
            $resolver->getLookupKey($plan, BillingInterval::Monthly);
        } catch (AiNotYetPurchasable $e) {
            self::assertSame($plan, $e->plan);

            throw $e;
        }
    }

    /** @return iterable<string, array{0: SubscriptionPlan}> */
    public static function aiVariantsProvider(): iterable
    {
        yield 'personal_ai' => [SubscriptionPlan::PersonalAi];
        yield 'pro_ai' => [SubscriptionPlan::ProAi];
        yield 'business_ai' => [SubscriptionPlan::BusinessAi];
    }

    #[DataProvider('aiVariantsProvider')]
    public function testAiVariantResolvesWhenPurchasable(SubscriptionPlan $plan): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: true);

        // When AI is purchasable the gate is lifted and the key resolves
        // normally — proving the gate is the only thing the flag controls.
        self::assertStringStartsWith('sendvery_', $resolver->getLookupKey($plan, BillingInterval::Annual));
    }
}

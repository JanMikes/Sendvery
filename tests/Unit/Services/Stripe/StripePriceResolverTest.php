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
    public function testResolvesPriceFromMapForEveryCombination(): void
    {
        $resolver = new StripePriceResolver(
            aiPurchasable: true,
            priceMap: [
                'personal_monthly' => 'price_personal_m',
                'personal_annual' => 'price_personal_y',
                'personal_ai_monthly' => 'price_personal_ai_m',
                'personal_ai_annual' => 'price_personal_ai_y',
                'pro_monthly' => 'price_pro_m',
                'pro_annual' => 'price_pro_y',
                'pro_ai_monthly' => 'price_pro_ai_m',
                'pro_ai_annual' => 'price_pro_ai_y',
                'business_monthly' => 'price_business_m',
                'business_annual' => 'price_business_y',
                'business_ai_monthly' => 'price_business_ai_m',
                'business_ai_annual' => 'price_business_ai_y',
            ],
        );

        self::assertSame('price_personal_m', $resolver->getPriceId(SubscriptionPlan::Personal, BillingInterval::Monthly));
        self::assertSame('price_personal_y', $resolver->getPriceId(SubscriptionPlan::Personal, BillingInterval::Annual));
        self::assertSame('price_personal_ai_m', $resolver->getPriceId(SubscriptionPlan::PersonalAi, BillingInterval::Monthly));
        self::assertSame('price_personal_ai_y', $resolver->getPriceId(SubscriptionPlan::PersonalAi, BillingInterval::Annual));
        self::assertSame('price_pro_m', $resolver->getPriceId(SubscriptionPlan::Pro, BillingInterval::Monthly));
        self::assertSame('price_pro_y', $resolver->getPriceId(SubscriptionPlan::Pro, BillingInterval::Annual));
        self::assertSame('price_pro_ai_m', $resolver->getPriceId(SubscriptionPlan::ProAi, BillingInterval::Monthly));
        self::assertSame('price_pro_ai_y', $resolver->getPriceId(SubscriptionPlan::ProAi, BillingInterval::Annual));
        self::assertSame('price_business_m', $resolver->getPriceId(SubscriptionPlan::Business, BillingInterval::Monthly));
        self::assertSame('price_business_y', $resolver->getPriceId(SubscriptionPlan::Business, BillingInterval::Annual));
        self::assertSame('price_business_ai_m', $resolver->getPriceId(SubscriptionPlan::BusinessAi, BillingInterval::Monthly));
        self::assertSame('price_business_ai_y', $resolver->getPriceId(SubscriptionPlan::BusinessAi, BillingInterval::Annual));
    }

    public function testFreePlanThrowsException(): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Free plan does not have a Stripe price.');
        $resolver->getPriceId(SubscriptionPlan::Free, BillingInterval::Monthly);
    }

    public function testUnlimitedPlanThrowsException(): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unlimited plan is internal-only');
        $resolver->getPriceId(SubscriptionPlan::Unlimited, BillingInterval::Annual);
    }

    #[DataProvider('aiVariantsProvider')]
    public function testAiVariantThrowsWhenNotPurchasable(SubscriptionPlan $plan): void
    {
        $resolver = new StripePriceResolver(aiPurchasable: false);

        $this->expectException(AiNotYetPurchasable::class);

        try {
            $resolver->getPriceId($plan, BillingInterval::Monthly);
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

    public function testFallsBackToEnvVarWhenNotInPriceMap(): void
    {
        $_ENV['STRIPE_PRICE_PRO_ANNUAL'] = 'price_from_env_xyz';

        try {
            $resolver = new StripePriceResolver(aiPurchasable: true);
            self::assertSame('price_from_env_xyz', $resolver->getPriceId(SubscriptionPlan::Pro, BillingInterval::Annual));
        } finally {
            unset($_ENV['STRIPE_PRICE_PRO_ANNUAL']);
        }
    }

    public function testMissingEnvVarThrowsRuntimeException(): void
    {
        // Ensure neither $_ENV nor $_SERVER has the value.
        unset($_ENV['STRIPE_PRICE_PERSONAL_MONTHLY'], $_SERVER['STRIPE_PRICE_PERSONAL_MONTHLY']);

        $resolver = new StripePriceResolver(aiPurchasable: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STRIPE_PRICE_PERSONAL_MONTHLY');
        $resolver->getPriceId(SubscriptionPlan::Personal, BillingInterval::Monthly);
    }
}

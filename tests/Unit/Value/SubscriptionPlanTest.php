<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SubscriptionPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('free', SubscriptionPlan::Free->value);
        self::assertSame('personal', SubscriptionPlan::Personal->value);
        self::assertSame('personal_ai', SubscriptionPlan::PersonalAi->value);
        self::assertSame('pro', SubscriptionPlan::Pro->value);
        self::assertSame('pro_ai', SubscriptionPlan::ProAi->value);
        self::assertSame('business', SubscriptionPlan::Business->value);
        self::assertSame('business_ai', SubscriptionPlan::BusinessAi->value);
        self::assertSame('unlimited', SubscriptionPlan::Unlimited->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(SubscriptionPlan::Personal, SubscriptionPlan::from('personal'));
        self::assertSame(SubscriptionPlan::BusinessAi, SubscriptionPlan::from('business_ai'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(SubscriptionPlan::tryFrom('enterprise'));
        // Old `team` value from the 2-tier model is no longer valid; clean-slate confirmed.
        self::assertNull(SubscriptionPlan::tryFrom('team'));
    }

    #[DataProvider('hasAiProvider')]
    public function testHasAi(SubscriptionPlan $plan, bool $expected): void
    {
        self::assertSame($expected, $plan->hasAi());
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: bool}> */
    public static function hasAiProvider(): iterable
    {
        yield 'free is no-AI' => [SubscriptionPlan::Free, false];
        yield 'personal base is no-AI' => [SubscriptionPlan::Personal, false];
        yield 'personal_ai has AI' => [SubscriptionPlan::PersonalAi, true];
        yield 'pro base is no-AI' => [SubscriptionPlan::Pro, false];
        yield 'pro_ai has AI' => [SubscriptionPlan::ProAi, true];
        yield 'business base is no-AI' => [SubscriptionPlan::Business, false];
        yield 'business_ai has AI' => [SubscriptionPlan::BusinessAi, true];
        yield 'unlimited has AI' => [SubscriptionPlan::Unlimited, true];
    }

    #[DataProvider('baseTierProvider')]
    public function testBaseTier(SubscriptionPlan $plan, SubscriptionPlan $expected): void
    {
        self::assertSame($expected, $plan->baseTier());
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: SubscriptionPlan}> */
    public static function baseTierProvider(): iterable
    {
        yield 'free → free' => [SubscriptionPlan::Free, SubscriptionPlan::Free];
        yield 'personal → personal' => [SubscriptionPlan::Personal, SubscriptionPlan::Personal];
        yield 'personal_ai → personal' => [SubscriptionPlan::PersonalAi, SubscriptionPlan::Personal];
        yield 'pro_ai → pro' => [SubscriptionPlan::ProAi, SubscriptionPlan::Pro];
        yield 'business_ai → business' => [SubscriptionPlan::BusinessAi, SubscriptionPlan::Business];
        yield 'unlimited → unlimited' => [SubscriptionPlan::Unlimited, SubscriptionPlan::Unlimited];
    }

    #[DataProvider('withAiProvider')]
    public function testWithAi(SubscriptionPlan $plan, SubscriptionPlan $expected): void
    {
        self::assertSame($expected, $plan->withAi());
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: SubscriptionPlan}> */
    public static function withAiProvider(): iterable
    {
        yield 'personal → personal_ai' => [SubscriptionPlan::Personal, SubscriptionPlan::PersonalAi];
        yield 'personal_ai stays personal_ai' => [SubscriptionPlan::PersonalAi, SubscriptionPlan::PersonalAi];
        yield 'pro → pro_ai' => [SubscriptionPlan::Pro, SubscriptionPlan::ProAi];
        yield 'pro_ai stays pro_ai' => [SubscriptionPlan::ProAi, SubscriptionPlan::ProAi];
        yield 'business → business_ai' => [SubscriptionPlan::Business, SubscriptionPlan::BusinessAi];
        yield 'business_ai stays business_ai' => [SubscriptionPlan::BusinessAi, SubscriptionPlan::BusinessAi];
        yield 'unlimited stays unlimited' => [SubscriptionPlan::Unlimited, SubscriptionPlan::Unlimited];
    }

    public function testWithAiOnFreeThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AI is not available on the Free tier');
        SubscriptionPlan::Free->withAi();
    }

    #[DataProvider('withoutAiProvider')]
    public function testWithoutAi(SubscriptionPlan $plan, SubscriptionPlan $expected): void
    {
        self::assertSame($expected, $plan->withoutAi());
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: SubscriptionPlan}> */
    public static function withoutAiProvider(): iterable
    {
        yield 'personal_ai → personal' => [SubscriptionPlan::PersonalAi, SubscriptionPlan::Personal];
        yield 'pro_ai → pro' => [SubscriptionPlan::ProAi, SubscriptionPlan::Pro];
        yield 'business_ai → business' => [SubscriptionPlan::BusinessAi, SubscriptionPlan::Business];
        yield 'personal stays personal' => [SubscriptionPlan::Personal, SubscriptionPlan::Personal];
        yield 'free stays free' => [SubscriptionPlan::Free, SubscriptionPlan::Free];
    }

    #[DataProvider('tierGroupProvider')]
    public function testTierGroup(SubscriptionPlan $plan, string $expected): void
    {
        self::assertSame($expected, $plan->tierGroup());
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: string}> */
    public static function tierGroupProvider(): iterable
    {
        yield 'free' => [SubscriptionPlan::Free, 'free'];
        yield 'personal' => [SubscriptionPlan::Personal, 'personal'];
        yield 'personal_ai groups with personal' => [SubscriptionPlan::PersonalAi, 'personal'];
        yield 'pro' => [SubscriptionPlan::Pro, 'pro'];
        yield 'pro_ai groups with pro' => [SubscriptionPlan::ProAi, 'pro'];
        yield 'business' => [SubscriptionPlan::Business, 'business'];
        yield 'business_ai groups with business' => [SubscriptionPlan::BusinessAi, 'business'];
        yield 'unlimited' => [SubscriptionPlan::Unlimited, 'unlimited'];
    }

    #[DataProvider('nextTierProvider')]
    public function testNextTier(SubscriptionPlan $plan, ?SubscriptionPlan $expected): void
    {
        self::assertSame($expected, $plan->nextTier());
    }

    /** @return iterable<string, array{0: SubscriptionPlan, 1: ?SubscriptionPlan}> */
    public static function nextTierProvider(): iterable
    {
        yield 'free → personal' => [SubscriptionPlan::Free, SubscriptionPlan::Personal];
        yield 'personal → pro' => [SubscriptionPlan::Personal, SubscriptionPlan::Pro];
        yield 'personal_ai → pro_ai' => [SubscriptionPlan::PersonalAi, SubscriptionPlan::ProAi];
        yield 'pro → business' => [SubscriptionPlan::Pro, SubscriptionPlan::Business];
        yield 'pro_ai → business_ai' => [SubscriptionPlan::ProAi, SubscriptionPlan::BusinessAi];
        yield 'business has no next tier' => [SubscriptionPlan::Business, null];
        yield 'business_ai has no next tier' => [SubscriptionPlan::BusinessAi, null];
        yield 'unlimited has no next tier' => [SubscriptionPlan::Unlimited, null];
    }
}

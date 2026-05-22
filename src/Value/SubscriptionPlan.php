<?php

declare(strict_types=1);

namespace App\Value;

enum SubscriptionPlan: string
{
    case Free = 'free';
    case Personal = 'personal';
    case PersonalAi = 'personal_ai';
    case Pro = 'pro';
    case ProAi = 'pro_ai';
    case Business = 'business';
    case BusinessAi = 'business_ai';
    // Internal-only tier granted by staff (see app:team:set-plan). Not exposed in marketing/pricing UI.
    case Unlimited = 'unlimited';

    public function hasAi(): bool
    {
        return match ($this) {
            self::PersonalAi, self::ProAi, self::BusinessAi, self::Unlimited => true,
            default => false,
        };
    }

    public function baseTier(): self
    {
        return match ($this) {
            self::PersonalAi => self::Personal,
            self::ProAi => self::Pro,
            self::BusinessAi => self::Business,
            default => $this,
        };
    }

    public function withAi(): self
    {
        return match ($this) {
            self::Personal, self::PersonalAi => self::PersonalAi,
            self::Pro, self::ProAi => self::ProAi,
            self::Business, self::BusinessAi => self::BusinessAi,
            self::Unlimited => self::Unlimited,
            self::Free => throw new \LogicException('AI is not available on the Free tier — direct the user to the contact form.'),
        };
    }

    public function withoutAi(): self
    {
        return $this->baseTier();
    }

    /**
     * UI grouping for pricing cards: groups *Ai variants under their base tier.
     */
    public function tierGroup(): string
    {
        return match ($this) {
            self::Free => 'free',
            self::Personal, self::PersonalAi => 'personal',
            self::Pro, self::ProAi => 'pro',
            self::Business, self::BusinessAi => 'business',
            self::Unlimited => 'unlimited',
        };
    }
}

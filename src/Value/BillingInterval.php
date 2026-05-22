<?php

declare(strict_types=1);

namespace App\Value;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /**
     * Stripe's recurring.interval string for this cadence ('month' or 'year').
     */
    public function stripeInterval(): string
    {
        return match ($this) {
            self::Monthly => 'month',
            self::Annual => 'year',
        };
    }
}

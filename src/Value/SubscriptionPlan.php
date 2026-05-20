<?php

declare(strict_types=1);

namespace App\Value;

enum SubscriptionPlan: string
{
    case Free = 'free';
    case Personal = 'personal';
    case Team = 'team';
    // Internal-only tier granted by staff (see app:team:set-plan). Not exposed in marketing/pricing UI.
    case Unlimited = 'unlimited';
}

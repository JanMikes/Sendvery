<?php

declare(strict_types=1);

namespace App\Value;

enum SubscriptionPlan: string
{
    case Free = 'free';
    case Personal = 'personal';
    case Team = 'team';
}

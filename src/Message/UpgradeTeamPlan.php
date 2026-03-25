<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\SubscriptionPlan;
use Ramsey\Uuid\UuidInterface;

final readonly class UpgradeTeamPlan
{
    public function __construct(
        public UuidInterface $teamId,
        public SubscriptionPlan $plan,
        public string $stripeSubscriptionId,
        public string $stripeCustomerId,
    ) {
    }
}

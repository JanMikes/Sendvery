<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SubscriptionPlan;

final readonly class BillingOverviewResult
{
    public function __construct(
        public SubscriptionPlan $plan,
        public ?string $stripeCustomerId,
        public ?string $stripeSubscriptionId,
        public ?\DateTimeImmutable $planWarningAt,
        public int $domainCount,
        public int $memberCount,
    ) {
    }

    /** @param array{plan: string, stripe_customer_id: ?string, stripe_subscription_id: ?string, plan_warning_at: ?string, domain_count: int|string, member_count: int|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            plan: SubscriptionPlan::from($row['plan']),
            stripeCustomerId: $row['stripe_customer_id'],
            stripeSubscriptionId: $row['stripe_subscription_id'],
            planWarningAt: null !== $row['plan_warning_at'] ? new \DateTimeImmutable($row['plan_warning_at']) : null,
            domainCount: (int) $row['domain_count'],
            memberCount: (int) $row['member_count'],
        );
    }
}

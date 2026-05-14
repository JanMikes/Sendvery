<?php

declare(strict_types=1);

namespace App\Events;

use App\Value\SubscriptionPlan;
use Ramsey\Uuid\UuidInterface;

final readonly class BetaAccessRequested
{
    public function __construct(
        public UuidInterface $requestId,
        public string $email,
        public string $name,
        public ?string $company,
        public SubscriptionPlan $requestedPlan,
        public ?int $domainCount,
        public ?string $message,
    ) {
    }
}

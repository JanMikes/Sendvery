<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\SubscriptionPlan;
use Ramsey\Uuid\UuidInterface;

final readonly class RequestBetaAccess
{
    public function __construct(
        public UuidInterface $requestId,
        public string $email,
        public string $name,
        public ?string $company,
        public SubscriptionPlan $requestedPlan,
        public ?int $domainCount,
        public ?string $message,
        public string $source,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class BetaSignupCreated
{
    public function __construct(
        public UuidInterface $signupId,
        public string $email,
        public string $confirmationToken,
    ) {
    }
}

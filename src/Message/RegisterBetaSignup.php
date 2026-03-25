<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class RegisterBetaSignup
{
    public function __construct(
        public UuidInterface $signupId,
        public string $email,
        public ?int $domainCount,
        public ?string $painPoint,
        public string $source,
    ) {
    }
}

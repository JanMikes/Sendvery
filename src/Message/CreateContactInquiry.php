<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class CreateContactInquiry
{
    public function __construct(
        public UuidInterface $inquiryId,
        public string $name,
        public string $email,
        public string $subject,
        public string $message,
        public ?string $submitterIp,
        public ?string $userAgent,
    ) {
    }
}

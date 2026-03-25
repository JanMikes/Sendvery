<?php

declare(strict_types=1);

namespace App\Value;

final readonly class MailMessage
{
    /**
     * @param array<MailAttachment> $attachments
     */
    public function __construct(
        public string $messageId,
        public string $subject,
        public string $from,
        public \DateTimeImmutable $date,
        public array $attachments,
    ) {
    }
}

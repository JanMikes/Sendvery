<?php

declare(strict_types=1);

namespace App\Value;

final readonly class MailMessage
{
    /**
     * @param array<MailAttachment> $attachments
     * @param string                $rawEml      the original message bytes, headers included
     */
    public function __construct(
        public string $messageId,
        public string $subject,
        public string $from,
        public \DateTimeImmutable $date,
        public array $attachments,
        public string $rawEml,
    ) {
    }
}

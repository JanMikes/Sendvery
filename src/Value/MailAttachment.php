<?php

declare(strict_types=1);

namespace App\Value;

final readonly class MailAttachment
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $mimeType,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Value;

readonly final class MailAttachment
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $mimeType,
    ) {
    }
}

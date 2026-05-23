<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\ToolNotifySource;
use Ramsey\Uuid\UuidInterface;

/**
 * TASK-006 — capture a soft email-me subscription from a tool result page.
 * Idempotent on `(email, source)`: re-submitting the same form is a no-op so
 * users can safely click "send me this report" twice without spamming
 * themselves with duplicate confirmation emails.
 */
final readonly class NotifyMeAboutTool
{
    public function __construct(
        public UuidInterface $signupId,
        public string $email,
        public string $domain,
        public ToolNotifySource $source,
    ) {
    }
}

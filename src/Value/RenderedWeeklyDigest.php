<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One team's weekly digest, rendered but not addressed to anybody.
 *
 * Separating "what the email says" from "who it goes to" is what lets the
 * digest be previewed: `sendvery:digest:send-all --preview` writes both
 * alternatives to disk through exactly the code path that would have mailed
 * them, so what a reviewer looks at in a browser is the email, not a
 * reconstruction of it.
 */
final readonly class RenderedWeeklyDigest
{
    public function __construct(
        public string $subject,
        public string $html,
        /** The text/plain alternative — a real part of the message, not a fallback nobody reads. */
        public string $text,
    ) {
    }
}

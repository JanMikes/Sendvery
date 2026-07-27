<?php

declare(strict_types=1);

namespace App\Value;

/**
 * A monitored domain as the setup checklist needs to talk about it: enough to
 * name it in a step title and to deep-link its DNS setup surface, nothing more.
 *
 * Exists because "Publish your DMARC record" with a generic "Do it →" button was
 * unanswerable for anyone with more than one domain — the step named no domain
 * and the CTA landed on the domains list, leaving the user to work out which of
 * their domains the checklist meant.
 */
final readonly class SetupChecklistDomain
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}

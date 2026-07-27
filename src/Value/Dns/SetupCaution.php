<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * A consequence the user should know about BEFORE they publish the record a
 * guided setup step is handing them — not a reason to stop, which is why these
 * sit under the record rather than replacing it.
 *
 * Two exist today, both on the report-delivery step:
 *  - RFC 7489 lets receivers cap `rua=` delivery to two addresses, so appending
 *    Sendvery to an already-full list can silently lose reports. Better to say so
 *    than to hand over a record that quietly does not work.
 *  - Sendvery only receives reports for a domain once an authorization TXT record
 *    exists in ITS zone. Whether that is automatic depends on the installation,
 *    so the copy differs.
 *
 * `key` doubles as the surface's test hook, so a caution can be asserted on
 * without pinning the sentence.
 */
final readonly class SetupCaution
{
    /**
     * @param string $key  stable identifier, also the `data-testid` on the rendered note
     * @param string $tone daisyUI semantic token driving the note's colour
     */
    public function __construct(
        public string $key,
        public string $text,
        public string $tone,
    ) {
    }
}

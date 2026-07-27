<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One concrete "why does this domain need attention?" line on the `/app`
 * attention list.
 *
 * The copy is never written here. `detail` is the verbatim
 * {@see \App\Results\ProtocolSetupStatus::$statusLine} the per-domain page
 * renders for the same protocol, so the dashboard cannot describe a problem in
 * words the domain page does not use — the single most common way two surfaces
 * start contradicting each other.
 *
 * Reasons render as non-interactive chips: the row already carries ONE deep
 * link to the fix surface, and per-chip anchors would nest inside it.
 */
final readonly class DomainAttentionReason
{
    /**
     * @param string $label  short protocol/subject name, e.g. `SPF`, `RUA destination`
     * @param string $detail one-line explanation, verbatim from the per-domain resolver
     * @param string $tone   daisyUI semantic token without the `text-`/`bg-` prefix: `error`, `warning` or `info`
     */
    public function __construct(
        public string $label,
        public string $detail,
        public string $tone,
    ) {
    }
}

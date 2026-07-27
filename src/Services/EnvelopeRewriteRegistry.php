<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Recognises an envelope sender that a relay rewrote on its way through
 * (DEC-060 WP-B).
 *
 * A forwarder that wants SPF to pass for *itself* has to replace the return
 * path, because the original envelope domain does not authorise the forwarder's
 * IP. The standard ways of doing that leave a mark: Sender Rewriting Scheme
 * (`SRS0=`/`SRS1=`), Bounce Address Tag Validation (`prvs=`/`btv1==`), and the
 * plain per-recipient bounce mailboxes (`bounces+…`, `bounce-…`) that list and
 * relay software has used for decades.
 *
 * **What this can and cannot prove.** It is a *shape*, not a credential. The
 * envelope sender is free text in the SMTP transaction, chosen by whoever
 * opened the connection, and SPF passing for it proves only that they control
 * the domain they claimed — which any attacker does for their own domain. So a
 * rewritten-looking envelope sits at the bottom of the DEC-060 evidence ladder
 * beside "volume and topology shape": it is a plausible forwarding story, and
 * it may keep Sendvery from *accusing* a sender, but it may never buy the
 * silence that {@see \App\Value\SenderRole::Forwarder} carries.
 * {@see SenderRoleClassifier} is where that restriction is enforced.
 *
 * **What the reports actually carry.** RFC 7489's `<auth_results><spf><domain>`
 * is defined as the checked *domain*, so on a conforming report the local-part
 * markers above never appear at all — `SRS0=…@srs.gateway.example` arrives as
 * `srs.gateway.example`. Both halves are therefore matched: the local-part
 * markers for the reporters that put the whole address in the field anyway, and
 * the rewriting-host labels (`srs`, `bounce`, `bounces`, …) for everyone else.
 *
 * Matching follows {@see ForwarderRegistry}: normalise, then exact or
 * `.`-boundary comparison, no regular expressions.
 */
final readonly class EnvelopeRewriteRegistry
{
    /**
     * Local-part prefixes that mark a rewritten return path.
     *
     * Compared case-insensitively against the start of the local part. SRS and
     * BATV both encode their payload after the marker, so a prefix test is the
     * whole of the syntax that matters.
     *
     * @var list<string>
     */
    private const array LOCAL_PART_PREFIXES = [
        // Sender Rewriting Scheme, one hop and two hops.
        'srs0=',
        'srs0-',
        'srs1=',
        'srs1-',
        // Bounce Address Tag Validation: `prvs=` is the simple form, `btv1==`
        // the tagged one.
        'prvs=',
        'btv1==',
        // Per-recipient bounce mailboxes — VERP by another name.
        'bounces+',
        'bounce-',
        'bounces-',
    ];

    /**
     * Leftmost hostname labels that identify a dedicated bounce or rewriting
     * host. These are what a conforming report leaves behind once the local
     * part is gone.
     *
     * Kept deliberately short. Every entry is a way for a sender to describe
     * itself, so a long list is a long list of self-descriptions — which is why
     * nothing here grants trust on its own.
     *
     * @var list<string>
     */
    private const array REWRITING_HOST_LABELS = [
        'srs',
        'srs0',
        'srs1',
        'bounce',
        'bounces',
        'bouncing',
        'bnc',
        'verp',
        'return',
        'returns',
    ];

    /**
     * @param string $envelopeSender the SPF-checked identity from the report —
     *                               normally a bare domain, occasionally a full
     *                               address from a non-conforming reporter
     */
    public function looksRewritten(string $envelopeSender): bool
    {
        $normalized = strtolower(trim($envelopeSender));

        if ('' === $normalized) {
            return false;
        }

        $atPosition = strrpos($normalized, '@');

        if (false !== $atPosition) {
            $localPart = substr($normalized, 0, $atPosition);

            foreach (self::LOCAL_PART_PREFIXES as $prefix) {
                if (str_starts_with($localPart, $prefix)) {
                    return true;
                }
            }

            $normalized = substr($normalized, $atPosition + 1);
        }

        $labels = explode('.', trim($normalized, '.'));

        // Leftmost label only. A `bounces` label buried in the middle of a name
        // says nothing, and matching anywhere would make `bounce.example.com`
        // and `mail.bounce-tracker.example` equally convincing.
        return count($labels) > 1 && in_array($labels[0], self::REWRITING_HOST_LABELS, true);
    }
}

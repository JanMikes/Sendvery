<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The per-IP authentication evidence a caller already has when it asks for a
 * sender identity (DEC-059 §3.3).
 *
 * These are deliberately *not* stored on `sender_identity`: authorization and
 * pass rates are per-team, per-domain judgements, while `sender_identity` is a
 * global cache of objective network facts. Callers that only know an IP can
 * omit them entirely and still get PTR-derived identity and role.
 *
 * Pass rates are percentages in the 0–100 range, matching the convention used
 * by the existing report queries and ReportFactsBuilder.
 */
final readonly class SenderAuthSignals
{
    public function __construct(
        public float $dkimPassRate,
        public float $spfPassRate,
        public bool $isAuthorized,
        public int $totalMessages,
        /**
         * What the *receiver* said about this host's mail (DEC-060, tier B).
         *
         * Defaulted rather than required, and deliberately so: a caller that
         * only has pass rates — the identity cache warming an IP, a test
         * describing an auth shape — is asserting nothing about receivers, and
         * "nobody attested anything" is the honest reading of its silence. It
         * grants no trust, so the default is also the safe one.
         */
        public ForwardingAttestation $forwarding = new ForwardingAttestation(),
        /**
         * Messages whose DKIM signature both verified *and* aligned with the
         * From domain (DEC-060, tier A). This is the cryptographic fact under
         * the {@see $dkimPassRate} heuristic: a signature that validates against
         * the header_from domain proves the message left that domain and
         * reached the receiver unmodified, and no spoofer can produce one.
         *
         * Separate from `$dkimPassRate` because a passing signature for
         * *somebody else's* domain proves nothing about this one — a relayed
         * newsletter still carries the newsletter vendor's valid signature.
         */
        public int $alignedDkimPassCount = 0,
        /**
         * Messages whose SPF-checked envelope domain does not align with the
         * From domain *and* looks like a rewritten return path — the mark a
         * forwarder leaves when it replaces the envelope so SPF passes for
         * itself ({@see \App\Services\EnvelopeRewriteRegistry}).
         *
         * Note what this deliberately does not claim: not that SPF *passed*.
         * `dmarc_record.spf_result` is the DMARC-evaluated verdict, so a
         * non-aligned pass is already recorded as a failure and the raw result
         * is not kept. The shape of the envelope is the whole of the evidence,
         * which is fitting — it is the weakest thing on the ladder either way,
         * since the envelope sender is chosen by whoever opened the SMTP
         * connection. It corroborates a forwarding story; it never establishes
         * one.
         */
        public int $rewrittenEnvelopeMessageCount = 0,
    ) {
    }

    /**
     * Convenience factory for the common case where the caller has raw message
     * counts straight out of an aggregate query rather than percentages.
     */
    public static function fromCounts(
        int $dkimPassed,
        int $spfPassed,
        int $totalMessages,
        bool $isAuthorized = false,
        ?ForwardingAttestation $forwarding = null,
        int $alignedDkimPassed = 0,
        int $rewrittenEnvelopeMessages = 0,
    ): self {
        return new self(
            dkimPassRate: $totalMessages > 0 ? $dkimPassed / $totalMessages * 100 : 0.0,
            spfPassRate: $totalMessages > 0 ? $spfPassed / $totalMessages * 100 : 0.0,
            isAuthorized: $isAuthorized,
            totalMessages: $totalMessages,
            forwarding: $forwarding ?? ForwardingAttestation::none(),
            alignedDkimPassCount: $alignedDkimPassed,
            rewrittenEnvelopeMessageCount: $rewrittenEnvelopeMessages,
        );
    }
}

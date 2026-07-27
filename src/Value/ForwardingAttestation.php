<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Whether a *receiver* stated that a message was forwarded (DEC-060, tier B).
 *
 * This is the strongest forwarding evidence obtainable without a network call,
 * and the only one on the ladder that the sending host cannot write itself. A
 * PTR record is published by whoever holds the IP block (tier D); an aggregate
 * report's `<policy_evaluated><reason>` is published by Gmail, Microsoft or
 * Yahoo describing what *they* decided to do with the mail. Nobody can make a
 * receiver claim they overrode a policy on their behalf.
 *
 * Three of RFC 7489 §6.7.3's six override types are direct statements that the
 * receiver believed the message was relayed — `forwarded`, `trusted_forwarder`
 * and `mailing_list`. A fourth, `local_policy`, is Gmail's ARC bucket: it says
 * "my own policy won over DMARC" and puts the actual finding in the free-text
 * comment, where an ARC chain that validated reads `arc=pass`. An ARC seal that
 * verifies *is* a relay attestation — an intermediary handled the message and
 * the receiver trusted its seal.
 *
 * `sampled_out` and `other` deliberately attest nothing. The first is about
 * `pct=`, not routing; the second is the RFC's catch-all, so reading forwarding
 * into it would mean granting trust on the strength of arbitrary text.
 *
 * Its present value is nil: **0 of the 81 DMARC reports in production carry a
 * `<reason>` element at all**. This is forward-looking — it pays off as domains
 * reach enforcement and receivers start overriding — and it explains nothing
 * about the data already collected.
 */
final readonly class ForwardingAttestation
{
    /**
     * Gmail's ARC verdict. Matched as a *whole token* rather than with a
     * substring test: the comment is free text chosen by the reporter, and it
     * ends up granting {@see SenderRole::Forwarder}, which suppresses alerts.
     * A substring match would accept `noarc=passed` and `arc=passfail` alike.
     */
    private const string ARC_PASS_TOKEN = 'arc=pass';

    /**
     * Everything that is not a token character ends a token. `=`, `-` and `_`
     * stay inside one so `arc=pass` is a single token and `dmarc=pass arc=pass`
     * is two; `.`, `;`, `,`, `(` and whitespace all terminate one, so trailing
     * punctuation never hides the token from an exact comparison.
     */
    private const string TOKEN_DELIMITERS = '/[^a-z0-9=_-]+/';

    public function __construct(
        public bool $attestsForwarding = false,
        /**
         * Which reason type carried the attestation, for the copy that has to
         * explain *why* Sendvery believes a gateway relayed this mail. Null
         * whenever nothing was attested.
         */
        public ?PolicyOverrideReasonType $attestedBy = null,
    ) {
    }

    /**
     * The default: no receiver said anything, which grants nothing.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * @param list<PolicyOverrideReason> $reasons every override reason a
     *                                            receiver recorded against one
     *                                            sending host, flattened across
     *                                            that host's records
     */
    public static function fromReasons(array $reasons): self
    {
        foreach ($reasons as $reason) {
            if (self::attests($reason)) {
                return new self(attestsForwarding: true, attestedBy: $reason->type);
            }
        }

        return self::none();
    }

    /**
     * Reads the `json_agg(policy_override_reasons)` shape the ingest queries
     * produce: one JSON array per grouped record, each holding that record's
     * reasons, and NULL when no record in the group was annotated at all —
     * which is every group in production today.
     *
     * Decoding lives here, beside the rule that consumes it, so that both
     * callers that build {@see SenderAuthSignals} stay one line and cannot
     * drift apart. It is forgiving in the safe direction only: a payload this
     * method cannot read degrades to "nothing was attested", never to trust.
     */
    public static function fromAggregatedJson(?string $json): self
    {
        if (null === $json) {
            return self::none();
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return self::none();
        }

        $reasons = [];

        foreach ($decoded as $perRecord) {
            foreach (is_array($perRecord) ? $perRecord : [] as $row) {
                if (!is_array($row) || !isset($row['type']) || !is_string($row['type'])) {
                    continue;
                }

                $comment = $row['comment'] ?? null;

                $reasons[] = new PolicyOverrideReason(
                    type: PolicyOverrideReasonType::fromReportValue($row['type']),
                    comment: is_string($comment) ? $comment : null,
                );
            }
        }

        return self::fromReasons($reasons);
    }

    private static function attests(PolicyOverrideReason $reason): bool
    {
        return match ($reason->type) {
            PolicyOverrideReasonType::Forwarded,
            PolicyOverrideReasonType::TrustedForwarder,
            PolicyOverrideReasonType::MailingList => true,
            PolicyOverrideReasonType::LocalPolicy => self::mentionsArcPass($reason->comment),
            // Unknown and vendor-specific tokens land in `Other`, so the last
            // arm has to be the one that grants nothing.
            PolicyOverrideReasonType::SampledOut,
            PolicyOverrideReasonType::Other => false,
        };
    }

    private static function mentionsArcPass(?string $comment): bool
    {
        if (null === $comment) {
            return false;
        }

        $tokens = preg_split(self::TOKEN_DELIMITERS, strtolower($comment)) ?: [];

        return in_array(self::ARC_PASS_TOKEN, $tokens, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Value\SenderAuthSignals;
use App\Value\SenderRole;

/**
 * Decides what a sending host is (DEC-059 §3.3). Deterministic, first match
 * wins, no AI anywhere near the ingest path.
 *
 * The ordering is the whole design:
 *
 *   1. OwnRelay   — the team already vouched for this IP.
 *   2. Forwarder  — the PTR says so, or the clean-forward auth signature does.
 *   3. Esp        — a recognised provider.
 *   4. Suspicious — fails everything, at volume, with no forwarding story.
 *   5. Unknown    — nothing identified it.
 *
 * Rule 2 sitting above rule 4 is not a stylistic choice: a body-rewriting
 * gateway fails DKIM *and* SPF, so on results alone it is a perfect match for
 * "spoofing". Checking the hostname first is what stops `ca.cloud-sec-av.com`
 * — the same product as the `eu.` host that passed DKIM cleanly — from being
 * reported as an attack (DEC-059 D12).
 */
final readonly class SenderRoleClassifier
{
    /**
     * The clean-forward signature: DKIM survives the hop (the body was not
     * touched) while SPF cannot, because the gateway is not in the original
     * sender's SPF record. Same thresholds ReportFactsBuilder already uses, so
     * the digest and the per-report AI insight agree on what forwarding is.
     */
    private const float FORWARDING_DKIM_MIN = 80.0;
    private const float FORWARDING_SPF_MAX = 30.0;

    /**
     * Below this, "fails everything" is not evidence of anything. Every failing
     * sender in the incident that triggered DEC-059 sent one or two messages
     * from a different continent — the long tail of forwarding. Real spoofing
     * campaigns are high volume; calling a two-message straggler an attacker is
     * how a monitoring product loses the user's trust.
     */
    private const int SUSPICIOUS_MIN_MESSAGES = 10;

    public function __construct(
        private ForwarderRegistry $forwarderRegistry,
    ) {
    }

    public function classify(?string $hostname, ?string $organization, ?SenderAuthSignals $signals): SenderRole
    {
        if (null !== $signals && $signals->isAuthorized) {
            return SenderRole::OwnRelay;
        }

        if (null !== $hostname && $this->forwarderRegistry->isForwarder($hostname)) {
            return SenderRole::Forwarder;
        }

        if (null !== $signals
            && $signals->dkimPassRate >= self::FORWARDING_DKIM_MIN
            && $signals->spfPassRate <= self::FORWARDING_SPF_MAX
        ) {
            return SenderRole::Forwarder;
        }

        if (null !== $organization) {
            return SenderRole::Esp;
        }

        if (null !== $signals
            && 0.0 === $signals->dkimPassRate
            && 0.0 === $signals->spfPassRate
            && $signals->totalMessages >= self::SUSPICIOUS_MIN_MESSAGES
        ) {
            return SenderRole::Suspicious;
        }

        return SenderRole::Unknown;
    }

    /**
     * The role stored in the global `sender_identity` cache: derived from the
     * hostname alone, so it says the same thing for every team.
     *
     * OwnRelay and Suspicious are excluded by construction — both depend on
     * per-team, per-domain evidence (`known_sender.is_authorized`, that
     * domain's pass rates), and a global row that claimed either would be
     * asserting one tenant's verdict over everyone else's mail.
     */
    public function baselineRole(?string $hostname, ?string $organization): SenderRole
    {
        return $this->classify($hostname, $organization, null);
    }
}

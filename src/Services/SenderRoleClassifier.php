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
 *   2. Forwarder  — a *confirmed* PTR says so, or the clean-forward auth
 *                   signature does.
 *   3. Esp        — a recognised provider.
 *   4. Suspicious — fails everything, at volume, with no forwarding story.
 *   5. Unknown    — nothing identified it.
 *
 * Rule 2 sitting above rule 4 is not a stylistic choice: a body-rewriting
 * gateway fails DKIM *and* SPF, so on results alone it is a perfect match for
 * "spoofing". Checking the hostname first is what stops `ca.cloud-sec-av.com`
 * — the same product as the `eu.` host that passed DKIM cleanly — from being
 * reported as an attack (DEC-059 D12).
 *
 * Which is precisely why the hostname half of rule 2 requires forward-confirmed
 * reverse DNS. It is the one branch that turns an attacker-writable string into
 * suppressed alerts, so the string has to be corroborated by a zone the
 * attacker does not control.
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

    /**
     * @param bool $hostnameForwardConfirmed whether $hostname passed
     *                                       forward-confirmed reverse DNS; see
     *                                       {@see Dns\ForwardConfirmedReverseDns}
     */
    public function classify(
        ?string $hostname,
        ?string $organization,
        ?SenderAuthSignals $signals,
        bool $hostnameForwardConfirmed,
    ): SenderRole {
        if (null !== $signals && $signals->isAuthorized) {
            return SenderRole::OwnRelay;
        }

        // The confirmation gate, and only here. A PTR record is written by
        // whoever holds the IP block, so `anything.mimecast.com` costs an
        // attacker one control-panel field — and it used to buy this branch,
        // which buys SenderRole::warrantsAlert() === false, which is silence on
        // the very alert that would have surfaced the spoofing.
        //
        // Failing confirmation is not itself an accusation: the sender simply
        // falls through to the rules below and is judged as Esp or Unknown like
        // any other host. The hostname is still stored and still displayed —
        // it remains the best label we have — only the trust is withheld.
        if (null !== $hostname
            && $hostnameForwardConfirmed
            && $this->forwarderRegistry->isForwarder($hostname)
        ) {
            return SenderRole::Forwarder;
        }

        // Deliberately ungated: this branch rests on cryptography, not on DNS.
        // A DKIM signature that still verifies after the hop proves the message
        // was relayed intact, whoever the relay claims to be, so it must keep
        // identifying forwarders when confirmation fails or there is no
        // hostname at all.
        if (null !== $signals
            && $signals->dkimPassRate >= self::FORWARDING_DKIM_MIN
            && $signals->spfPassRate <= self::FORWARDING_SPF_MAX
        ) {
            return SenderRole::Forwarder;
        }

        // Gated for the same reason as the forwarder branch, and more urgently:
        // $organization is resolved from the PTR hostname alone, and Esp is just
        // as alert-suppressing as Forwarder. OrganizationMapper recognises ~60
        // names against ForwarderRegistry's handful, so an unconfirmed PTR of
        // `x.sendgrid.net` or `x.protection.outlook.com` was the WIDER way to buy
        // silence. An unconfirmed claim earns a label, never trust.
        if (null !== $organization && $hostnameForwardConfirmed) {
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
    public function baselineRole(
        ?string $hostname,
        ?string $organization,
        bool $hostnameForwardConfirmed,
    ): SenderRole {
        return $this->classify($hostname, $organization, null, $hostnameForwardConfirmed);
    }
}

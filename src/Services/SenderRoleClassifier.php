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
 *   2. Forwarder  — an aligned DKIM signature survived the hop, or a receiver
 *                   attested the forward, or a *confirmed* PTR says so, or the
 *                   clean-forward auth signature does.
 *   3. Esp        — a recognised provider.
 *   4. Suspicious — fails everything, at volume, with no forwarding story.
 *   5. Unknown    — nothing identified it.
 *
 * Within rule 2 the branches are themselves ordered by how hard the evidence is
 * to forge (DEC-060 §1.2), strongest first:
 *
 *   A. an aligned DKIM signature that verified — cryptographic, unforgeable;
 *   B. the receiver's own policy-override reason — written by Gmail, not by the
 *      sender;
 *   D. a forward-confirmed PTR hostname, then the aggregate DKIM/SPF shape.
 *
 * Tier E — the shape of the envelope sender — appears nowhere in rule 2, and
 * that placement is the point rather than an omission: see
 * {@see hasForwardingStory()}.
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

        // Tier A — the top of the ladder, and the only rule here resting on
        // mathematics rather than on somebody's word.
        //
        // A DKIM signature that verifies against the *header_from* domain proves
        // two things at once: the message really left that domain, and no byte
        // the signature covers changed on the way. A spoofer cannot produce one
        // without the private key. So when that signature survives while SPF
        // does not, the message was relayed intact by a host the original domain
        // never authorised — which is the definition of a forward.
        //
        // Stated as its own rule rather than left inside the percentage
        // heuristic below because the two are not the same claim. The heuristic
        // asks "did most messages pass DKIM?", which a valid signature for
        // somebody else's domain also satisfies; this asks "did a signature for
        // *your* domain survive?", which nothing else can satisfy. One message
        // is enough — the proof does not get stronger by repetition.
        if (null !== $signals
            && $signals->alignedDkimPassCount > 0
            && $signals->spfPassRate <= self::FORWARDING_SPF_MAX
        ) {
            return SenderRole::Forwarder;
        }

        // Tier B — the receiver's own statement, and the highest-ranked piece of
        // evidence on the ladder that Sendvery can obtain rather than be told.
        //
        // Why it outranks every branch below: those all rest on something the
        // sending side controls or produces. A PTR record is written by whoever
        // holds the IP block; pass rates are a property of the mail the sender
        // chose to send. `<policy_evaluated><reason>` is written by Gmail or
        // Microsoft about a decision *they* made, and no amount of control over
        // the sending host can put it there.
        //
        // Why it does not outrank OwnRelay: that branch is the operator's own
        // first-hand statement about their own infrastructure, and it produces
        // the more informative answer — "your relay" rather than "somebody's
        // gateway". Both suppress alerts, so nothing is lost by preferring it.
        //
        // Not cached: this is per-report, per-receiver evidence, so it must
        // never reach the globally shared `sender_identity` row. baselineRole()
        // passes no signals, which is what keeps it out.
        if (null !== $signals && $signals->forwarding->attestsForwarding) {
            return SenderRole::Forwarder;
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
            && !$this->hasForwardingStory($signals)
        ) {
            return SenderRole::Suspicious;
        }

        return SenderRole::Unknown;
    }

    /**
     * Tier E — evidence too weak to grant trust, but strong enough to withhold
     * an accusation.
     *
     * A forwarder that wants SPF to pass for itself rewrites the return path,
     * and the standard schemes leave a mark: `SRS0=`, `prvs=`, a `bounces.`
     * host. Seeing one on an envelope domain that does *not* align with the From
     * header is the signature of a forward with a rewritten return path — and it
     * is also exactly why the messages read as total failures, since a
     * non-aligned SPF pass is recorded by the receiver as a DMARC failure.
     *
     * It deliberately does NOT return SenderRole::Forwarder, and this is the
     * one place in DEC-060 where the plan's own tier table is overruled. The
     * envelope sender is free text in the SMTP transaction, chosen by whoever
     * opened the connection; SPF passing for it proves only that they control
     * the domain they named, which every attacker does for their own domain.
     * `MAIL FROM: SRS0=x=y=victim.com=user@attacker.example`, with SPF published
     * for attacker.example, satisfies every part of the test — so treating it as
     * tier C and letting it grant Forwarder would have sold the alert-suppressing
     * role for the price of one DNS record. That is the exact hole DEC-060 was
     * written to close, one field further down the message.
     *
     * Cross-receiver correlation (WP-C) joins it here for the same reason, and
     * the plan asks explicitly that the limit be encoded rather than left to
     * rule ordering. Its own weakness is different but no smaller: every field
     * of a *failing* record is chosen by whoever sent it, `d=` included, so a
     * spoofer naming the victim's own domain has the passing half of the
     * correlation supplied by the victim's real mail.
     *
     * Downgrading Suspicious to Unknown is the whole of what either may buy:
     * the sender stops being called an attacker on this evidence, and still
     * shows up for review — {@see SenderRole::Unknown} warrants an alert just as
     * Suspicious does. Withholding an accusation is free; withholding an alert
     * is not.
     */
    private function hasForwardingStory(SenderAuthSignals $signals): bool
    {
        return $signals->rewrittenEnvelopeMessageCount > 0
            || $signals->signedStreamSeenFromAnotherHost;
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

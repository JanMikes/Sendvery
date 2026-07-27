<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\ForwardedMailExplanation;
use App\Results\ReportSenderGroupResult;
use App\Value\ForwardedMailOutcome;
use App\Value\SenderRole;

/**
 * Explains a forwarder's undelivered mail, deterministically (DEC-060 WP-E).
 *
 * WHY this exists at all. Production, 2026-07-27: a domain publishing
 * `p=quarantine` had six legitimate messages sitting in spam folders. All six
 * came through recipient-side security gateways — `cloud-sec-av.com`,
 * `inkyphishfence.com` and Microsoft's own forwarder — which received the mail,
 * rewrote the bodies for link protection, and re-injected them. SPF cannot
 * survive that hop, and a rewritten body invalidates the DKIM signature, so
 * DMARC had nothing left to align and the receiver did exactly what it was
 * told to do.
 *
 * The domain owner caused none of it and can fix none of it. Classifying those
 * senders correctly (DEC-059) and then printing "fix your misconfigured sending
 * sources" underneath would have wasted the entire effort — which is precisely
 * what DEC-059 was written about. So this service produces the *only* honest
 * thing there is to say, including the part where the answer is "nothing".
 *
 * A forwarder for this purpose is one identified by either of the two evidence
 * tiers that can stand alone: the cached role, which requires forward-confirmed
 * reverse DNS or the clean-forward auth signature, or a receiver's own policy
 * override. Tiers D and below never reach here on their own — the classifier
 * has already applied that ordering by the time a role is stored.
 */
final readonly class ForwardedMailExplainer
{
    public function explain(ReportSenderGroupResult $group): ForwardedMailExplanation
    {
        if (!$this->isForwarder($group)) {
            return ForwardedMailExplanation::nothingToExplain();
        }

        $held = $group->dispositionQuarantine + $group->dispositionReject;

        // A forwarder whose mail all arrived needs no explanation. Narrating
        // success is how a product trains people to skim past its own text.
        if ($held <= 0) {
            return ForwardedMailExplanation::nothingToExplain();
        }

        // Reject outranks quarantine when a sender's mail met both: it is the
        // harsher outcome for the recipient, who never saw the message at all.
        $rejected = $group->dispositionReject > 0;

        return new ForwardedMailExplanation(
            outcome: $rejected ? ForwardedMailOutcome::Rejected : ForwardedMailOutcome::Quarantined,
            affectedMessages: $held,
            headline: $this->headline($rejected, $held),
            whyItHappened: $this->whyItHappened($group),
            whatYouCanDo: $this->whatYouCanDo(),
        );
    }

    private function isForwarder(ReportSenderGroupResult $group): bool
    {
        return SenderRole::Forwarder === $group->senderRole
            || $group->forwarding->attestsForwarding;
    }

    /**
     * "not a fault at your end" is in the headline rather than buried in the
     * body on purpose: the count is the part a skimming reader takes away, and
     * a bare "3 messages quarantined" reads as an incident.
     */
    private function headline(bool $rejected, int $held): string
    {
        $messages = sprintf('%d forwarded %s', $held, 1 === $held ? 'message' : 'messages');

        if ($rejected) {
            return sprintf('%s %s refused — expected, and not a fault at your end', $messages, 1 === $held ? 'was' : 'were');
        }

        return $messages.' went to spam — expected, and not a fault at your end';
    }

    private function whyItHappened(ReportSenderGroupResult $group): string
    {
        return sprintf(
            '%s is a mail gateway on the recipient\'s side. It accepted mail sent as your domain, scanned it and re-injected it under its own address. SPF cannot survive that hop by design — the gateway is not in your SPF record, and it must not be. %s With neither method left aligned, the receiver applied the DMARC policy you publish.',
            $group->displayLabel,
            $this->dkimClause($group),
        );
    }

    /**
     * The DKIM half is what separates a forward that got through from one that
     * did not, so it is stated from the counts rather than assumed. Production
     * showed both halves inside a single gateway product on a single day: one
     * message relayed untouched and delivered, three rewritten and quarantined.
     */
    private function dkimClause(ReportSenderGroupResult $group): string
    {
        if ($group->dkimPassCount <= 0) {
            return 'The DKIM signature did not survive either, because the gateway rewrote the message — link protection, external-sender banners and disclaimers all change the body a signature covers.';
        }

        return sprintf(
            'The DKIM signature did survive on %d of these %d messages, which is why those were delivered; the gateway rewrote the rest — link protection, external-sender banners and disclaimers all change the body a signature covers.',
            $group->dkimPassCount,
            $group->totalMessages,
        );
    }

    /**
     * Deliberately constant. There is no branch here because there is no input
     * that would make a different answer true: the reader does not operate the
     * gateway, and no record they can publish extends SPF or DKIM across a hop
     * that rewrites the message. The last sentence is not padding — a user who
     * reads "your mail is in spam" and finds no other action available will
     * reach for the one lever they do control and weaken their DMARC policy,
     * undoing real protection to fix mail that was never broken.
     */
    private function whatYouCanDo(): string
    {
        return 'Nothing, and that is the accurate answer rather than a shrug: you do not run this gateway, and no SPF or DKIM change carries across a hop that rewrites the message. Accept it as a known cost of forwarding — receivers that check the gateway\'s ARC seal already deliver this mail, which is why the same gateway succeeds elsewhere. It is not a reason to weaken your DMARC policy.';
    }
}

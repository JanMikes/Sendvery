<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\SenderActivity30Day;
use App\Results\SenderAdvisorResult;
use App\Results\SenderInventoryResult;
use App\Value\SenderAdvisorSeverity;

/**
 * Picks the per-sender row callout on `/app/domains/{id}/senders` (TASK-092).
 * Pure deterministic computation over a {@see SenderInventoryResult} row plus
 * a {@see SenderActivity30Day} aggregate. The pattern mirrors
 * {@see DmarcPolicyAdvisor} / {@see MailboxHealthAdvisor}: one branch per
 * actionable shape, returns a None result for everything else so the template
 * can call this for every row and let the {@see SenderAdvisorSeverity} enum
 * decide whether the row callout renders.
 *
 * The eligibility rules below are the ENTIRE business logic for the "make a
 * decision" prompts — tests own these numbers, the controller wires inputs in,
 * and the template renders the output. Nothing in the rendering layer ever
 * re-derives any of these thresholds.
 *
 * The thresholds (50 messages / 90% DKIM for recommend_authorize;
 * 20 / 50% for recommend_revoke) are chosen so a marketing IP sending
 * legitimate mail for a week clears the authorize bar, while a spoofer
 * sending failing mail crosses the revoke bar before they accumulate enough
 * volume to drown the genuine traffic in the inventory.
 */
final readonly class SenderAuthorizationAdvisor
{
    private const int RECOMMEND_AUTHORIZE_MIN_MESSAGES_30D = 50;
    private const float RECOMMEND_AUTHORIZE_MIN_PASS_RATE_30D = 90.0;

    private const int RECOMMEND_REVOKE_MIN_MESSAGES_30D = 20;
    private const float RECOMMEND_REVOKE_MAX_PASS_RATE_30D = 50.0;

    // Floor for the Monitor branch — below this we suppress the row callout
    // entirely; a brand-new sender with one or two messages isn't worth a
    // banner of any kind.
    private const int MONITOR_MIN_MESSAGES_30D = 5;

    public function advise(SenderInventoryResult $sender, SenderActivity30Day $activity): SenderAdvisorResult
    {
        // Authorized senders never get a "make a decision" callout — they ARE
        // a decision. Same shape as the {@see DmarcPolicyAdvisor}'s "Reject is
        // terminal" branch: the user has already done what we'd nudge them
        // toward, so silence is the right output.
        if ($sender->isAuthorized) {
            return SenderAdvisorResult::none($sender->id);
        }

        if ($activity->totalMessages < self::MONITOR_MIN_MESSAGES_30D) {
            return SenderAdvisorResult::none($sender->id);
        }

        if ($this->shouldRecommendAuthorize($sender, $activity)) {
            return new SenderAdvisorResult(
                senderId: $sender->id,
                severity: SenderAdvisorSeverity::RecommendAuthorize,
                reasonText: sprintf(
                    '%s has sent %s messages as this domain in the last 30 days with %s%% DMARC pass. Looks legitimate — authorize to stop being alerted.',
                    $sender->organization ?? 'This sender',
                    number_format($activity->totalMessages, 0, '.', ','),
                    self::formatRate($activity->dkimPassRate),
                ),
                primaryActionLabel: 'Authorize sender',
            );
        }

        if ($this->shouldRecommendRevoke($sender, $activity)) {
            $who = null !== $sender->organization && '' !== $sender->organization
                ? $sender->organization
                : sprintf('Unknown sender at %s', $sender->sourceIp);

            return new SenderAdvisorResult(
                senderId: $sender->id,
                severity: SenderAdvisorSeverity::RecommendRevoke,
                reasonText: sprintf(
                    '%s has sent %s failing messages as this domain — likely spoofing. Mark as revoked to make this visible in your alerts.',
                    $who,
                    number_format($activity->totalMessages, 0, '.', ','),
                ),
                primaryActionLabel: 'Mark as revoked',
            );
        }

        // Between-thresholds case: enough volume to mention, not enough to
        // commit to a verdict. The callout doesn't render for Monitor (the
        // template only opens a row banner for the two "decision" severities)
        // but the result is non-None so the stat row can still count it.
        return new SenderAdvisorResult(
            senderId: $sender->id,
            severity: SenderAdvisorSeverity::Monitor,
            reasonText: sprintf(
                'Watching %s — not enough volume yet to recommend authorize or revoke.',
                null !== $sender->organization && '' !== $sender->organization
                    ? $sender->organization
                    : $sender->sourceIp,
            ),
            primaryActionLabel: null,
        );
    }

    private function shouldRecommendAuthorize(SenderInventoryResult $sender, SenderActivity30Day $activity): bool
    {
        if ($activity->totalMessages < self::RECOMMEND_AUTHORIZE_MIN_MESSAGES_30D) {
            return false;
        }

        if ($activity->dkimPassRate < self::RECOMMEND_AUTHORIZE_MIN_PASS_RATE_30D) {
            return false;
        }

        // We need to know who this is before we recommend "authorize". Without
        // an organisation name the user can't sanity-check the suggestion;
        // turning it into a one-click authorize for an unknown IP turns the
        // page into a phishing risk surface.
        return null !== $sender->organization && '' !== $sender->organization;
    }

    private function shouldRecommendRevoke(SenderInventoryResult $sender, SenderActivity30Day $activity): bool
    {
        if ($activity->totalMessages < self::RECOMMEND_REVOKE_MIN_MESSAGES_30D) {
            return false;
        }

        if ($activity->dkimPassRate >= self::RECOMMEND_REVOKE_MAX_PASS_RATE_30D) {
            return false;
        }

        // Inverse rule: we only recommend revoke when we DON'T know who this
        // is. A known organisation with a low pass rate is more likely
        // misconfigured than malicious — direct them to the inventory but
        // don't pre-stamp "spoofing".
        return null === $sender->organization || '' === $sender->organization;
    }

    private static function formatRate(float $rate): string
    {
        // Trim a trailing .0 — "100% DMARC pass" reads cleaner than "100.0%".
        return rtrim(rtrim(number_format($rate, 1), '0'), '.');
    }
}

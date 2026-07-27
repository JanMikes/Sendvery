<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Decides whether a domain's unreviewed senders are worth an email of their own
 * (see `sendvery:senders:review-reminder`).
 *
 * A raw count is the wrong trigger. Ten senders that each delivered one message
 * are noise — probably a forwarder or a stray probe. One sender carrying most of
 * a domain's mail while nobody has vouched for it is the thing worth interrupting
 * somebody's day about. So the test is volume, in two shapes:
 *
 *  (a) one unreviewed sender has on its own sent at least
 *      {@see MATERIAL_SENDER_MIN_MESSAGES} messages, or
 *  (b) the unreviewed senders together carry at least
 *      {@see SHARE_TEST_MIN_MESSAGES} messages AND at least
 *      {@see SHARE_TEST_MIN_SHARE_PERCENT}% of the domain's total mail.
 *
 * (a) reuses {@see \App\Services\SenderAuthorizationAdvisor}'s authorize floor
 * on purpose: 50 messages is already the point at which we consider a sender's
 * history substantial enough to have an opinion about it, so it is also the
 * point at which the user's silence about it becomes worth a nudge. (b) catches
 * the swarm case that (a) misses — many small senders that add up to a real
 * share of the domain's traffic.
 */
final readonly class SenderReviewMateriality
{
    public const int MATERIAL_SENDER_MIN_MESSAGES = 50;
    public const int SHARE_TEST_MIN_MESSAGES = 20;
    public const float SHARE_TEST_MIN_SHARE_PERCENT = 5.0;

    public static function isMaterial(
        int $largestSenderMessages,
        int $unreviewedMessages,
        int $domainMessages,
    ): bool {
        if ($largestSenderMessages >= self::MATERIAL_SENDER_MIN_MESSAGES) {
            return true;
        }

        if ($unreviewedMessages < self::SHARE_TEST_MIN_MESSAGES) {
            return false;
        }

        return self::sharePercent($unreviewedMessages, $domainMessages) >= self::SHARE_TEST_MIN_SHARE_PERCENT;
    }

    /**
     * Share of the domain's known mail carried by unreviewed senders. A domain
     * with no recorded volume at all cannot have a share — returning 0 keeps the
     * share test from dividing by zero and from firing on empty data.
     */
    public static function sharePercent(int $unreviewedMessages, int $domainMessages): float
    {
        if ($domainMessages <= 0) {
            return 0.0;
        }

        return $unreviewedMessages / $domainMessages * 100;
    }
}

<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The single vocabulary for "where does this sender stand?".
 *
 * Before this enum the app had four labels for two states — the filter tabs
 * said "Unauthorized", the badge said "Unknown", the summary chip said
 * "unknown", the bulk button said "Mark unknown" and the per-row button said
 * "Revoke". A user reading the inventory could not tell whether any of those
 * words meant the same thing, or whether an amber badge was asking them to do
 * something. Every surface now derives its wording from here.
 *
 * The third state matters as much as the wording: "we have never asked anyone
 * about this sender" and "a human looked at it and said it is not ours" demand
 * different words and different urgency. Collapsing both into one amber badge
 * is what made the original screenshot unreadable.
 *
 * NOTE on how {@see NotAuthorized} is detected: `known_sender` has no decision
 * column, so "a human has touched this row" is read off
 * `KnownSender::$updatedAt`. That timestamp is also stamped by
 * {@see \App\Entity\KnownSender::setNotes()}, so writing a note about a sender
 * and leaving it unauthorized reads as NotAuthorized. That is deliberate and
 * defensible — someone who annotated the row *has* reviewed it — but a
 * dedicated `decided_at` column would be exact, and is the right follow-up if
 * the distinction ever needs to be airtight.
 */
enum SenderReviewState: string
{
    case Authorized = 'authorized';
    case NeedsReview = 'needs_review';
    case NotAuthorized = 'not_authorized';

    public static function fromFlags(bool $isAuthorized, bool $wasReviewed): self
    {
        if ($isAuthorized) {
            return self::Authorized;
        }

        return $wasReviewed ? self::NotAuthorized : self::NeedsReview;
    }

    public function label(): string
    {
        return match ($this) {
            self::Authorized => 'Authorized',
            self::NeedsReview => 'Needs review',
            self::NotAuthorized => 'Not authorized',
        };
    }

    /**
     * daisyUI semantic badge token. Amber for the one state that is asking the
     * user for something, red for a sender they have actively rejected, green
     * for a settled one.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Authorized => 'badge-success',
            self::NeedsReview => 'badge-warning',
            self::NotAuthorized => 'badge-error',
        };
    }

    /**
     * The sentence that answers "what does this badge mean, and is something
     * expected of me?". Rendered as the badge's `title` and spelled out in the
     * status legend, so a user never has to guess what amber wants.
     */
    public function meaning(): string
    {
        return match ($this) {
            self::Authorized => 'You confirmed this server is allowed to send mail as your domain. Nothing to do.',
            self::NeedsReview => 'Sendvery has seen this server sending as your domain, but nobody has decided about it yet. Tell us whether it is yours.',
            self::NotAuthorized => 'You reviewed this server and said it is not yours. It stays flagged so it cannot quietly become normal.',
        };
    }

    /**
     * True for the one state that is a request to the user. Drives the
     * "N senders are waiting for your review" call to action.
     */
    public function needsDecision(): bool
    {
        return self::NeedsReview === $this;
    }

    /**
     * The state to show for a row that stands for a GROUP of addresses — an
     * organisation on the Top Senders table or a report's sender pane — rather
     * than a single inventory entry.
     *
     * Worst-first, deliberately: a provider running five machines where one is
     * still unreviewed is not "Authorized". The naive alternative,
     * MAX(is_authorized), reported the whole group as settled the moment ONE
     * address was, which is how a group could look green while hiding a sender
     * nobody had decided about.
     *
     * Null means no inventory row backs the group at all — "not tracked yet",
     * which is not the same as "needs review" and must not borrow its amber.
     *
     * Lives on the enum so every grouped surface derives the state identically;
     * it used to be copied privately into each result DTO, and a second copy is
     * how two pages start disagreeing.
     */
    public static function worstOfGroup(int $knownSenderCount, int $needsReviewCount, int $authorizedCount): ?self
    {
        if (0 === $knownSenderCount) {
            return null;
        }

        // Anything left over once reviewed-and-accepted and never-reviewed are
        // accounted for was actively rejected, which outranks both.
        if ($knownSenderCount > $needsReviewCount + $authorizedCount) {
            return self::NotAuthorized;
        }

        if ($needsReviewCount > 0) {
            return self::NeedsReview;
        }

        return self::Authorized;
    }
}

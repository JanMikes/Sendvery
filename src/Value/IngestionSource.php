<?php

declare(strict_types=1);

namespace App\Value;

/**
 * A pipeline that can put DMARC reports into Sendvery.
 *
 * The point of naming these is to be able to say "our side is healthy" with
 * evidence. Without it, a domain going quiet is indistinguishable from our own
 * poller being stuck, and the product has historically resolved that ambiguity
 * by blaming the user's DNS.
 *
 * BYO mailboxes are NOT a case here: each one is a row in `mailbox_connection`
 * with its own `last_polled_at`/`last_error`, so its health is already per-row.
 * This enum covers the shared infrastructure that has no natural owning row.
 */
enum IngestionSource: string
{
    case CentralInbox = 'central_inbox';

    public function label(): string
    {
        return match ($this) {
            self::CentralInbox => 'Central reports inbox',
        };
    }

    /**
     * How long this source may go without a successful poll before an operator
     * should be told the pipeline itself is suspect.
     *
     * The central inbox is polled every 5 minutes by cron, so an hour is twelve
     * consecutive missed runs — comfortably past a transient IMAP hiccup or a
     * single slow batch, and still far short of the 72h domain-silence floor,
     * which matters: our own staleness has to be detectable BEFORE we start
     * accusing users of a broken `rua=` tag.
     */
    public function stalenessThreshold(): \DateInterval
    {
        return match ($this) {
            self::CentralInbox => new \DateInterval('PT1H'),
        };
    }
}

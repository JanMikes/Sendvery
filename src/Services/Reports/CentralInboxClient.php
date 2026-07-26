<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Value\ConnectionTestResult;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;

/**
 * Talks to the central reports@sendvery.com IMAP mailbox.
 *
 * Lifecycle: fetchPending() opens a session and returns up to batchSize
 * envelopes; the session stays open so subsequent markSeen()/moveProcessed()
 * calls reuse the same connection. Callers MUST call close() in a finally
 * block.
 *
 * Messages stay in INBOX until processing finishes. Ingestion only flags
 * them \Seen (fetchPending() fetches unseen only), which keeps the UID
 * captured at fetch time valid for the final move. Moving the message to a
 * holding folder earlier would invalidate that UID and force a Message-ID
 * search — which cannot be trusted right after a move: Seznam indexes IMAP
 * SEARCH lazily, so a just-moved message is invisible to it for a while.
 */
interface CentralInboxClient
{
    /** @return list<FetchedEnvelope> */
    public function fetchPending(): array;

    /**
     * Flags an INBOX message \Seen so the next fetchPending() skips it.
     * Called right after the envelope is persisted.
     */
    public function markSeen(int $uid): void;

    /**
     * Moves a fully-processed INBOX message to its destination folder.
     * Prefers the UID captured at fetch time (guarded by UIDVALIDITY); falls
     * back to a Message-ID search when the UID generation has been
     * invalidated or the UID fetch fails.
     */
    public function moveProcessed(?int $uid, ?int $uidvalidity, string $messageId, CentralInboxFolder $destination): void;

    public function close(): void;

    public function testConnection(): ConnectionTestResult;
}

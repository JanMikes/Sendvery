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
 * envelopes; the session stays open so subsequent moveToFolder() calls reuse
 * the same connection. Callers MUST call close() in a finally block.
 */
interface CentralInboxClient
{
    /** @return list<FetchedEnvelope> */
    public function fetchPending(): array;

    public function moveToFolder(int $uid, CentralInboxFolder $folder): void;

    /**
     * Finds a previously-moved message by its RFC 5322 Message-Id and moves
     * it to another folder. Used to move envelopes from Pending → Processed
     * (or Failed/Junk) after the worker finishes — by then the original IMAP
     * UID we captured at fetch time is no longer valid because the message
     * has been moved.
     */
    public function moveByMessageId(string $messageId, CentralInboxFolder $from, CentralInboxFolder $to): void;

    public function close(): void;

    public function testConnection(): ConnectionTestResult;
}

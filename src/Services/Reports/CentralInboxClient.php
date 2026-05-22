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

    public function close(): void;

    public function testConnection(): ConnectionTestResult;
}

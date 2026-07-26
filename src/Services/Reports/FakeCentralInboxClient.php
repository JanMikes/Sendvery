<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Value\ConnectionTestResult;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;

/**
 * In-memory replacement for ImapCentralInboxClient used by tests. Aliased
 * via config/services.php under when@test so every code path that fetches
 * from the central inbox sees scripted envelopes instead of live IMAP.
 */
final class FakeCentralInboxClient implements CentralInboxClient
{
    /** @var list<FetchedEnvelope> */
    private array $pending = [];

    /** @var list<int> */
    private array $seenUids = [];

    /** @var list<array{uid: ?int, uidvalidity: ?int, messageId: string, destination: CentralInboxFolder}> */
    private array $movedProcessed = [];

    private bool $shouldFail = false;
    private string $failureMessage = '';
    private int $closedTimes = 0;

    /** @return list<FetchedEnvelope> */
    public function fetchPending(): array
    {
        if ($this->shouldFail) {
            throw new \RuntimeException($this->failureMessage);
        }

        $batch = $this->pending;
        $this->pending = [];

        return $batch;
    }

    public function markSeen(int $uid): void
    {
        $this->seenUids[] = $uid;
    }

    public function moveProcessed(?int $uid, ?int $uidvalidity, string $messageId, CentralInboxFolder $destination): void
    {
        $this->movedProcessed[] = [
            'uid' => $uid,
            'uidvalidity' => $uidvalidity,
            'messageId' => $messageId,
            'destination' => $destination,
        ];
    }

    public function close(): void
    {
        ++$this->closedTimes;
    }

    public function testConnection(): ConnectionTestResult
    {
        if ($this->shouldFail) {
            return new ConnectionTestResult(success: false, error: $this->failureMessage, mailboxCount: 0);
        }

        return new ConnectionTestResult(success: true, error: null, mailboxCount: count($this->pending));
    }

    public function addEnvelope(FetchedEnvelope $envelope): void
    {
        $this->pending[] = $envelope;
    }

    public function simulateFailure(string $message = 'Central inbox connection failed'): void
    {
        $this->shouldFail = true;
        $this->failureMessage = $message;
    }

    /** @return list<int> */
    public function getSeenUids(): array
    {
        return $this->seenUids;
    }

    /** @return list<array{uid: ?int, uidvalidity: ?int, messageId: string, destination: CentralInboxFolder}> */
    public function getMovedProcessed(): array
    {
        return $this->movedProcessed;
    }

    public function getClosedTimes(): int
    {
        return $this->closedTimes;
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->seenUids = [];
        $this->movedProcessed = [];
        $this->closedTimes = 0;
        $this->shouldFail = false;
        $this->failureMessage = '';
    }
}

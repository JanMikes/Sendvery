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

    /** @var array<int, CentralInboxFolder> */
    private array $moved = [];

    /** @var array<string, array{from: CentralInboxFolder, to: CentralInboxFolder}> */
    private array $movedByMessageId = [];

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

    public function moveToFolder(int $uid, CentralInboxFolder $folder): void
    {
        $this->moved[$uid] = $folder;
    }

    public function moveByMessageId(string $messageId, CentralInboxFolder $from, CentralInboxFolder $to): void
    {
        $this->movedByMessageId[$messageId] = ['from' => $from, 'to' => $to];
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

    /** @return array<int, CentralInboxFolder> */
    public function getMovedUids(): array
    {
        return $this->moved;
    }

    /** @return array<string, array{from: CentralInboxFolder, to: CentralInboxFolder}> */
    public function getMovedByMessageId(): array
    {
        return $this->movedByMessageId;
    }

    public function getClosedTimes(): int
    {
        return $this->closedTimes;
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->moved = [];
        $this->movedByMessageId = [];
        $this->closedTimes = 0;
        $this->shouldFail = false;
        $this->failureMessage = '';
    }
}

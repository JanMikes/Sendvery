<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Entity\MailboxConnection;
use App\Value\ConnectionTestResult;
use App\Value\MailMessage;

final class FakeMailClient implements MailClient
{
    /** @var array<MailMessage> */
    private array $messages = [];

    /** @var array<string> */
    private array $processedMessageIds = [];

    private bool $shouldFail = false;

    private string $failureMessage = '';

    /** @return iterable<MailMessage> */
    public function fetchDmarcReports(MailboxConnection $connection): iterable
    {
        if ($this->shouldFail) {
            throw new \RuntimeException($this->failureMessage);
        }

        return $this->messages;
    }

    public function markAsProcessed(MailboxConnection $connection, MailMessage $message): void
    {
        $this->processedMessageIds[] = $message->messageId;
    }

    public function testConnection(MailboxConnection $connection): ConnectionTestResult
    {
        if ($this->shouldFail) {
            return new ConnectionTestResult(
                success: false,
                error: $this->failureMessage,
                mailboxCount: 0,
            );
        }

        return new ConnectionTestResult(
            success: true,
            error: null,
            mailboxCount: count($this->messages),
        );
    }

    public function addMessage(MailMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function simulateFailure(string $message = 'Connection failed'): void
    {
        $this->shouldFail = true;
        $this->failureMessage = $message;
    }

    /** @return array<string> */
    public function getProcessedMessageIds(): array
    {
        return $this->processedMessageIds;
    }

    public function reset(): void
    {
        $this->messages = [];
        $this->processedMessageIds = [];
        $this->shouldFail = false;
        $this->failureMessage = '';
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PollReportsInbox;
use App\Services\Reports\ReportEmailIngestor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PollReportsInboxHandler
{
    public function __construct(
        private ReportEmailIngestor $ingestor,
    ) {
    }

    public function __invoke(PollReportsInbox $message): void
    {
        $this->ingestor->ingestBatch();
    }
}

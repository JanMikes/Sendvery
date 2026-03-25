<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\MailboxPollCompleted;
use App\Message\PollMailbox;
use App\Message\ProcessDmarcReport;
use App\Repository\MailboxConnectionRepository;
use App\Services\Dmarc\ReportAttachmentExtractor;
use App\Services\IdentityProvider;
use App\Services\Mail\MailClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class PollMailboxHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailboxConnectionRepository $connectionRepository,
        private MailClient $mailClient,
        private ReportAttachmentExtractor $extractor,
        private IdentityProvider $identityProvider,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollMailbox $message): void
    {
        $connection = $this->connectionRepository->get($message->connectionId);

        if (!$connection->isActive) {
            return;
        }

        $reportsFound = 0;
        $errors = 0;

        try {
            $mailMessages = $this->mailClient->fetchDmarcReports($connection);

            foreach ($mailMessages as $mailMessage) {
                foreach ($mailMessage->attachments as $attachment) {
                    try {
                        $xmlFiles = $this->extractor->extract($attachment->content, $attachment->filename);

                        foreach ($xmlFiles as $xmlContent) {
                            $reportId = $this->identityProvider->nextIdentity();
                            $domainId = $connection->monitoredDomain?->id;

                            if ($domainId === null) {
                                $this->logger->warning('Mailbox connection {connectionId} has no monitored domain, skipping report.', [
                                    'connectionId' => $connection->id->toString(),
                                ]);
                                $errors++;
                                continue;
                            }

                            $this->commandBus->dispatch(new ProcessDmarcReport(
                                reportId: $reportId,
                                domainId: $domainId,
                                xmlContent: $xmlContent,
                            ));

                            $reportsFound++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to process attachment {filename}: {error}', [
                            'filename' => $attachment->filename,
                            'error' => $e->getMessage(),
                            'connectionId' => $connection->id->toString(),
                        ]);
                        $errors++;
                    }
                }

                try {
                    $this->mailClient->markAsProcessed($connection, $mailMessage);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark message as processed: {error}', [
                        'error' => $e->getMessage(),
                        'messageId' => $mailMessage->messageId,
                    ]);
                }
            }

            $connection->markPolled($this->clock->now());
        } catch (\Throwable $e) {
            $connection->markError($e->getMessage());
            $this->logger->error('Mailbox poll failed for connection {connectionId}: {error}', [
                'connectionId' => $connection->id->toString(),
                'error' => $e->getMessage(),
            ]);
        }

        $connection->recordThat(new MailboxPollCompleted(
            connectionId: $connection->id,
            reportsFound: $reportsFound,
            errors: $errors,
        ));

        $this->entityManager->flush();
    }
}

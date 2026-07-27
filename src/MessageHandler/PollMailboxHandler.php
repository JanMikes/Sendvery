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
use App\Services\Stripe\PlanEnforcement;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Polls one BYO mailbox and pushes every DMARC report it finds into the normal
 * pipeline — the same pipeline, and the same monthly report cap, as the central
 * inbox. The cap used to be checked only on the central-inbox path, so whether
 * a team was capped depended on how its reports happened to arrive.
 *
 * Over-cap reports are LEFT IN THE MAILBOX (the message is not flagged
 * processed) rather than quarantined: the user's own mailbox is a durable copy,
 * so nothing is lost and the next poll after an upgrade or a period roll picks
 * them up. Quarantining is not an option here — a quarantine row requires a
 * `received_report_email` envelope, which only the central inbox creates.
 */
#[AsMessageHandler]
final readonly class PollMailboxHandler
{
    public function __construct(
        private MailboxConnectionRepository $connectionRepository,
        private MailClient $mailClient,
        private ReportAttachmentExtractor $extractor,
        private IdentityProvider $identityProvider,
        private MessageBusInterface $commandBus,
        private PlanEnforcement $planEnforcement,
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

        // Asked once per run and counted down locally: the counter behind it
        // only moves as each dispatched report is parsed, so re-reading it
        // mid-loop would let a single poll walk past the cap.
        $allowance = $this->planEnforcement->remainingMonthlyReportAllowance(
            $connection->team->id->toString(),
            $connection->team->getSubscriptionPlan(),
        );

        try {
            $mailMessages = $this->mailClient->fetchDmarcReports($connection);

            foreach ($mailMessages as $mailMessage) {
                $heldBackByPlanCap = false;

                foreach ($mailMessage->attachments as $attachment) {
                    try {
                        $xmlFiles = $this->extractor->extract($attachment->content, $attachment->filename);

                        foreach ($xmlFiles as $xmlContent) {
                            $domainId = $connection->monitoredDomain?->id;

                            if (null === $domainId) {
                                $this->logger->warning('Mailbox connection {connectionId} has no monitored domain, skipping report.', [
                                    'connectionId' => $connection->id->toString(),
                                ]);
                                ++$errors;

                                continue;
                            }

                            if ($reportsFound >= $allowance) {
                                $heldBackByPlanCap = true;

                                continue;
                            }

                            $this->commandBus->dispatch(new ProcessDmarcReport(
                                reportId: $this->identityProvider->nextIdentity(),
                                domainId: $domainId,
                                xmlContent: $xmlContent,
                            ));

                            ++$reportsFound;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to process attachment {filename}: {error}', [
                            'filename' => $attachment->filename,
                            'error' => $e->getMessage(),
                            'connectionId' => $connection->id->toString(),
                        ]);
                        ++$errors;
                    }
                }

                if ($heldBackByPlanCap) {
                    // Deliberately NOT marked processed: leaving the message in
                    // the mailbox is what keeps the report from being lost, and
                    // the next poll after an upgrade or a period roll ingests it.
                    // Re-ingesting an already-parsed sibling report is harmless —
                    // ProcessDmarcReportHandler dedupes on the report id.
                    \Sentry\addBreadcrumb(\Sentry\Breadcrumb::fromArray([
                        'category' => 'plan.report_cap_hit',
                        'level' => 'warning',
                        'data' => [
                            'team_id' => $connection->team->id->toString(),
                            'connection_id' => $connection->id->toString(),
                        ],
                    ]));

                    $this->logger->warning('Monthly report cap reached for team {teamId}; leaving mailbox message {messageId} for a later poll.', [
                        'teamId' => $connection->team->id->toString(),
                        'messageId' => $mailMessage->messageId,
                    ]);

                    continue;
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
    }
}

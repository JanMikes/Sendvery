<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MailboxConnection;
use App\Entity\ReceivedReportEmail;
use App\Events\MailboxPollCompleted;
use App\Message\PollMailbox;
use App\Message\ProcessDmarcReport;
use App\Repository\MailboxConnectionRepository;
use App\Repository\ReceivedReportEmailRepository;
use App\Services\Dmarc\ReportAttachmentExtractor;
use App\Services\IdentityProvider;
use App\Services\Mail\MailClient;
use App\Services\Stripe\PlanEnforcement;
use App\Value\MailMessage;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * them up.
 *
 * EVERY polled mail is recorded as a `received_report_email` envelope, the same
 * ledger the central inbox writes. That ledger is what the mailbox detail page
 * counts, what the reports list filters by mailbox on, and what the ingestion
 * matrix reads to tell a domain whether it is fed by DNS, a mailbox or both.
 * Writing only the reports and not the envelope made a mailbox that had been
 * ingesting happily for months display zeroes on all of them — the reading a
 * user is most likely to take as "my mailbox is broken".
 *
 * Recording is deduped on (source, message-id) BOTH across polls and within a
 * single poll, and the two need different mechanisms. Across polls the stored
 * row is found by query. Within one poll a query is blind: an entity persisted
 * a moment ago is invisible to SQL until something flushes, and the only thing
 * that flushes mid-loop is a successful `ProcessDmarcReport` dispatch going out
 * through the command bus. A batch in which nothing parses — a mailbox with no
 * monitored domain, a team at its cap, two truncated archives — would persist
 * both rows and hit the unique index. Hence the in-memory map.
 *
 * That failure mode is worth spelling out, because it is not merely "the poll
 * fails". `PollMailbox` is not routed to a transport, so the whole poll is ONE
 * transaction and the violation lands at the middleware's closing flush, after
 * this handler has returned — past the try/catch below, so `markError()` never
 * runs and nothing is recorded anywhere. The rollback discards every envelope,
 * report and usage increment in the batch. But `markAsProcessed()` sets the
 * IMAP \Seen flag inside the loop, is not transactional, and the client only
 * ever fetches `->unseen()`. Mails processed before the collision are therefore
 * flagged read on a server the rollback cannot reach and are never fetched
 * again: those reports are gone, and the batch re-fails every fifteen minutes.
 */
#[AsMessageHandler]
final readonly class PollMailboxHandler
{
    public function __construct(
        private MailboxConnectionRepository $connectionRepository,
        private ReceivedReportEmailRepository $envelopeRepository,
        private MailClient $mailClient,
        private ReportAttachmentExtractor $extractor,
        private IdentityProvider $identityProvider,
        private MessageBusInterface $commandBus,
        private PlanEnforcement $planEnforcement,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        /*
         * Deliberately the SAME ceiling the central inbox refuses oversized mail
         * at, read from the same env var rather than a new knob nobody has set:
         * both paths write whole message bytes into `raw_eml`, so both need the
         * same limit on how much of a customer's mailbox we take custody of.
         * Read as a value rather than by injecting CentralInboxConfig, so the
         * BYO path stays independent of a service a self-hoster may have left
         * switched off entirely.
         */
        #[Autowire(env: 'int:SENDVERY_REPORTS_INBOX_MAX_MESSAGE_BYTES')]
        private int $maxMessageBytes,
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

        /** @var array<string, ReceivedReportEmail> $recordedThisPoll "{connection id}|{message id}" => envelope */
        $recordedThisPoll = [];

        try {
            $mailMessages = $this->mailClient->fetchDmarcReports($connection);

            foreach ($mailMessages as $mailMessage) {
                $now = $this->clock->now();
                $envelope = $this->recordEnvelope($connection, $mailMessage, $now, $recordedThisPoll);
                $envelope->incrementAttempts();

                if ($this->exceedsStorageLimit($mailMessage)) {
                    // Not flagged processed: the mail stays where it is, and the
                    // customer's mailbox is now the only copy of it there is.
                    $this->logger->warning('Skipping oversized mailbox message ({size} bytes) on connection {connectionId}.', [
                        'size' => \strlen($mailMessage->rawEml),
                        'limit' => $this->maxMessageBytes,
                        'connectionId' => $connection->id->toString(),
                    ]);
                    $envelope->markFailed(sprintf(
                        'This email is %d bytes, over the %d-byte limit we will store. It was left in your mailbox and its contents were not kept.',
                        \strlen($mailMessage->rawEml),
                        $this->maxMessageBytes,
                    ), $now);
                    ++$errors;

                    continue;
                }

                $heldBackByPlanCap = false;
                $dispatched = 0;
                $failures = [];
                $ignoredReason = null;

                foreach ($mailMessage->attachments as $attachment) {
                    try {
                        $xmlFiles = $this->extractor->extract($attachment->content, $attachment->filename);

                        foreach ($xmlFiles as $xmlContent) {
                            $domainId = $connection->monitoredDomain?->id;

                            if (null === $domainId) {
                                $this->logger->warning('Mailbox connection {connectionId} has no monitored domain, skipping report.', [
                                    'connectionId' => $connection->id->toString(),
                                ]);
                                $ignoredReason = 'This mailbox is not linked to a monitored domain, so there is nothing to file its reports under.';
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
                                // The link the mailbox filter on /app/reports and
                                // the mailbox detail page's parsed count both read.
                                sourceEnvelopeId: $envelope->id,
                            ));

                            ++$reportsFound;
                            ++$dispatched;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to process attachment {filename}: {error}', [
                            'filename' => $attachment->filename,
                            'error' => $e->getMessage(),
                            'connectionId' => $connection->id->toString(),
                        ]);
                        $failures[] = $attachment->filename.': '.$e->getMessage();
                        ++$errors;
                    }
                }

                $this->finalizeEnvelope($envelope, $dispatched, $failures, $heldBackByPlanCap, $ignoredReason, $now);

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

    /**
     * @param array<string, ReceivedReportEmail> $recordedThisPoll keyed by connection id + message id
     */
    private function recordEnvelope(
        MailboxConnection $connection,
        MailMessage $mailMessage,
        \DateTimeImmutable $now,
        array &$recordedThisPoll,
    ): ReceivedReportEmail {
        $messageId = $this->messageIdFor($connection, $mailMessage);

        // In-memory first, and it has to stay first: a row persisted earlier in
        // this same loop is invisible to the query below until something
        // flushes, and nothing reliably does.
        //
        // Both halves are scoped to THIS connection. Message-ID is a header the
        // sender chose and `ReportSource::ByoMailbox` is the same value for
        // every customer, so an unscoped lookup asks "has anyone on the platform
        // seen this header?" — and two tenants receiving one reporter message is
        // ordinary (a domain with `rua=` pointed at both its owner and its
        // agency), not adversarial. The map is keyed the same way for the same
        // reason, even though one poll only ever touches one connection: the key
        // should not be one refactor away from meaning something wider than the
        // row it stands for.
        $scopedKey = $connection->id->toString().'|'.$messageId;

        $existing = $recordedThisPoll[$scopedKey]
            ?? $this->envelopeRepository->findForSourceAndMessageId(ReportSource::ByoMailbox, $messageId, $connection);

        if (null !== $existing) {
            $recordedThisPoll[$scopedKey] = $existing;

            return $existing;
        }

        $envelope = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::ByoMailbox,
            messageId: $messageId,
            fromAddress: $mailMessage->from,
            subject: $mailMessage->subject,
            receivedAt: $mailMessage->date,
            ingestedAt: $now,
            // The TRUE size even when we decline to keep the bytes, so the
            // reason a mail was refused is legible from the row itself.
            sizeBytes: \strlen($mailMessage->rawEml),
            rawEml: $this->exceedsStorageLimit($mailMessage) ? '' : $mailMessage->rawEml,
            // IMAP UIDs exist so the CENTRAL inbox can move a message it has
            // already read; a BYO mailbox is re-found by Message-ID instead.
            mailboxConnection: $connection,
        );

        $this->entityManager->persist($envelope);
        $recordedThisPoll[$scopedKey] = $envelope;

        return $envelope;
    }

    private function exceedsStorageLimit(MailMessage $mailMessage): bool
    {
        return \strlen($mailMessage->rawEml) > $this->maxMessageBytes;
    }

    /**
     * `uniq_envelope_source_msgid` is a UNIQUE index over (source, message_id),
     * so without this every mail lacking a Message-ID header would collapse into
     * a single envelope: a mailbox holding two of them would report one
     * delivery, and the second mail's reports would be filed against the first
     * mail's envelope — a wrong count and wrong provenance, silently. An
     * outright constraint violation happens only when nothing in the batch
     * parses; see the class docblock for why that case is the worse one.
     *
     * Deriving the id from the bytes keeps such mails distinct from each other.
     * It also keeps a re-polled mail identical to itself, which is what the
     * dedupe needs — with one caveat worth knowing: the hash covers
     * `fullRawEml()`, i.e. raw headers plus body, so a server that rewrites
     * headers between fetches (mbox-style `Status:` / `X-Status:` flags being
     * the classic case) yields a new id and therefore a second envelope. The
     * alternative — hashing the body alone — would merge genuinely different
     * mails that happen to quote the same report, which is the worse error.
     */
    private function messageIdFor(MailboxConnection $connection, MailMessage $mailMessage): string
    {
        if ('' !== trim($mailMessage->messageId)) {
            return $mailMessage->messageId;
        }

        return sprintf(
            '<no-message-id-%s-%s@sendvery.invalid>',
            $connection->id->toString(),
            hash('sha256', $mailMessage->rawEml),
        );
    }

    /**
     * @param list<string> $failures
     */
    private function finalizeEnvelope(
        ReceivedReportEmail $envelope,
        int $dispatched,
        array $failures,
        bool $heldBackByPlanCap,
        ?string $ignoredReason,
        \DateTimeImmutable $now,
    ): void {
        if ($dispatched > 0) {
            $envelope->markParsed($now);

            return;
        }

        if (EnvelopeProcessingStatus::Parsed === $envelope->processingStatus) {
            // A second copy, in this same poll, of a mail we already parsed. It
            // has nothing to add, and it must not erase the fact that the first
            // copy worked.
            return;
        }

        if ([] !== $failures) {
            $envelope->markFailed(implode('; ', $failures), $now);

            return;
        }

        if ($heldBackByPlanCap) {
            // Not Quarantined: a quarantine row holds the only copy of a report,
            // and here the copy still sits unread in the customer's own mailbox.
            // Saying "quarantined" would claim we are holding something we are not.
            $envelope->markIgnored('Held back by this month\'s report cap; left in the mailbox for the next poll.', $now);

            return;
        }

        $envelope->markIgnored($ignoredReason ?? 'No DMARC report could be extracted from this email.', $now);
    }
}

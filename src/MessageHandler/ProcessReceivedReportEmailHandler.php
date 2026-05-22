<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Message\ProcessDmarcReport;
use App\Message\ProcessReceivedReportEmail;
use App\Repository\ReceivedReportEmailRepository;
use App\Services\Dmarc\DmarcXmlParser;
use App\Services\Dmarc\ReportAttachmentExtractor;
use App\Services\IdentityProvider;
use App\Services\Reports\CentralInboxClient;
use App\Services\Reports\DmarcReportRouter;
use App\Services\Reports\RawEmailMimeParser;
use App\Services\Stripe\PlanEnforcement;
use App\Value\ParsedDmarcReport;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\RoutingDecision;
use App\Value\Reports\RoutingKind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Heart of the central-inbox pipeline. Takes one received envelope, walks
 * the MIME tree, extracts every DMARC XML report inside, and either routes
 * it to a verified team (via the existing ProcessDmarcReport handler) or
 * parks it in quarantine until a team verifies the matching domain.
 *
 * Per-XML errors are isolated: a bad report doesn't poison sibling reports
 * in the same envelope. The envelope's final status reflects the best
 * outcome across all XMLs (parsed > quarantined > ignored > failed).
 */
#[AsMessageHandler]
final readonly class ProcessReceivedReportEmailHandler
{
    public function __construct(
        private ReceivedReportEmailRepository $envelopeRepository,
        private RawEmailMimeParser $mimeParser,
        private ReportAttachmentExtractor $attachmentExtractor,
        private DmarcXmlParser $parser,
        private DmarcReportRouter $router,
        private MessageBusInterface $commandBus,
        private IdentityProvider $identityProvider,
        private EntityManagerInterface $entityManager,
        private CentralInboxClient $client,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private PlanEnforcement $planEnforcement,
        #[Autowire(env: 'int:SENDVERY_QUARANTINE_TTL_DAYS')]
        private int $quarantineTtlDays,
    ) {
    }

    public function __invoke(ProcessReceivedReportEmail $message): void
    {
        $envelope = $this->envelopeRepository->get($message->envelopeId);
        $envelope->incrementAttempts();
        $now = $this->clock->now();

        $destination = $this->processEnvelope($envelope, $now);

        $this->entityManager->flush();

        try {
            $this->client->moveByMessageId($envelope->messageId, CentralInboxFolder::Pending, $destination);
        } catch (\Throwable $e) {
            // IMAP move is best-effort: the envelope is fully processed regardless.
            $this->logger->warning('Failed to move envelope {msgId} to {folder}: {error}', [
                'msgId' => $envelope->messageId,
                'folder' => $destination->name,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->client->close();
        }
    }

    private function processEnvelope(ReceivedReportEmail $envelope, \DateTimeImmutable $now): CentralInboxFolder
    {
        try {
            $attachments = $this->mimeParser->extractAttachments($envelope->rawEmlBytes());
        } catch (\Throwable $e) {
            $envelope->markFailed('Cannot parse MIME: '.$e->getMessage(), $now);

            return CentralInboxFolder::Failed;
        }

        if ([] === $attachments) {
            $envelope->markIgnored('No attachments — not a DMARC report.', $now);

            return CentralInboxFolder::Junk;
        }

        $routedCount = 0;
        $quarantinedCount = 0;
        $errorMessages = [];

        foreach ($attachments as $attachment) {
            try {
                $xmlBlobs = $this->attachmentExtractor->extract($attachment->content, $attachment->filename);
            } catch (\Throwable $e) {
                $errorMessages[] = $attachment->filename.': '.$e->getMessage();

                continue;
            }

            foreach ($xmlBlobs as $xml) {
                try {
                    $parsed = $this->parser->parse($xml);
                } catch (\Throwable $e) {
                    $errorMessages[] = 'XML parse failure: '.$e->getMessage();

                    continue;
                }

                $decision = $this->router->route($parsed->policyDomain);

                if (RoutingKind::Routed === $decision->kind) {
                    if ($this->teamIsAtMonthlyReportCap($decision)) {
                        // Per `never-delete-user-data`: don't drop the report,
                        // quarantine it so the team can revisit on upgrade.
                        $this->quarantineOverageReport($envelope, $decision, $parsed, $xml, $now);
                        ++$quarantinedCount;
                    } else {
                        $this->dispatchToTeam($envelope, $decision, $xml);
                        ++$routedCount;
                    }
                } elseif (RoutingKind::Quarantined === $decision->kind) {
                    $this->quarantineReport($envelope, $decision, $parsed, $xml, $now);
                    ++$quarantinedCount;
                } else {
                    $this->logger->info('Ignored report from envelope {msgId}: {reason}', [
                        'msgId' => $envelope->messageId,
                        'reason' => $decision->ignoredReason,
                    ]);
                }
            }
        }

        return $this->finalizeStatus($envelope, $routedCount, $quarantinedCount, $errorMessages, $now);
    }

    private function teamIsAtMonthlyReportCap(RoutingDecision $decision): bool
    {
        assert(null !== $decision->domain);
        $team = $decision->domain->team;

        return !$this->planEnforcement->canParseReport($team->id->toString(), $team->getSubscriptionPlan());
    }

    private function quarantineOverageReport(
        ReceivedReportEmail $envelope,
        RoutingDecision $decision,
        ParsedDmarcReport $parsed,
        string $xml,
        \DateTimeImmutable $now,
    ): void {
        assert(null !== $decision->domain);

        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantined = new QuarantinedDmarcReport(
            id: $this->identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: $decision->domain->domain,
            externalReportId: $parsed->reportId,
            reporterOrg: $parsed->reporterOrg,
            reporterEmail: $parsed->reporterEmail,
            dateRangeBegin: $parsed->dateRangeBegin,
            dateRangeEnd: $parsed->dateRangeEnd,
            quarantinedAt: $now,
            expiresAt: $now->modify('+'.$this->quarantineTtlDays.' days'),
            reason: QuarantineReason::PlanOverage,
            reportXmlGz: $compressed,
        );

        $this->entityManager->persist($quarantined);
    }

    private function dispatchToTeam(ReceivedReportEmail $envelope, RoutingDecision $decision, string $xml): void
    {
        assert(null !== $decision->domain);

        $this->commandBus->dispatch(new ProcessDmarcReport(
            reportId: $this->identityProvider->nextIdentity(),
            domainId: $decision->domain->id,
            xmlContent: $xml,
            sourceEnvelopeId: $envelope->id,
        ));
    }

    private function quarantineReport(
        ReceivedReportEmail $envelope,
        RoutingDecision $decision,
        ParsedDmarcReport $parsed,
        string $xml,
        \DateTimeImmutable $now,
    ): void {
        assert(null !== $decision->domainName);
        assert(null !== $decision->quarantineReason);

        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantined = new QuarantinedDmarcReport(
            id: $this->identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: $decision->domainName,
            externalReportId: $parsed->reportId,
            reporterOrg: $parsed->reporterOrg,
            reporterEmail: $parsed->reporterEmail,
            dateRangeBegin: $parsed->dateRangeBegin,
            dateRangeEnd: $parsed->dateRangeEnd,
            quarantinedAt: $now,
            expiresAt: $now->modify('+'.$this->quarantineTtlDays.' days'),
            reason: $decision->quarantineReason,
            reportXmlGz: $compressed,
        );

        $this->entityManager->persist($quarantined);
    }

    /**
     * @param list<string> $errorMessages
     */
    private function finalizeStatus(
        ReceivedReportEmail $envelope,
        int $routedCount,
        int $quarantinedCount,
        array $errorMessages,
        \DateTimeImmutable $now,
    ): CentralInboxFolder {
        if ($routedCount > 0) {
            $envelope->markParsed($now);

            return CentralInboxFolder::Processed;
        }

        if ($quarantinedCount > 0) {
            $envelope->markQuarantined($now);

            return CentralInboxFolder::Processed;
        }

        if ([] !== $errorMessages) {
            $envelope->markFailed(implode('; ', $errorMessages), $now);

            return CentralInboxFolder::Failed;
        }

        $envelope->markIgnored('No DMARC XML found in attachments.', $now);

        return CentralInboxFolder::Junk;
    }
}

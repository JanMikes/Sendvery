<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessDmarcReport;
use App\Message\ReleaseQuarantinedReportsForDomain;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Services\IdentityProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ReleaseQuarantinedReportsForDomainHandler
{
    public function __construct(
        private QuarantinedDmarcReportRepository $quarantineRepository,
        private MessageBusInterface $commandBus,
        private IdentityProvider $identityProvider,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReleaseQuarantinedReportsForDomain $message): void
    {
        $reports = $this->quarantineRepository->findForDomain($message->domainName);

        if ([] === $reports) {
            return;
        }

        $this->logger->info('Releasing {count} quarantined report(s) for newly-verified domain {domain}.', [
            'count' => count($reports),
            'domain' => $message->domainName,
        ]);

        foreach ($reports as $quarantined) {
            $this->commandBus->dispatch(new ProcessDmarcReport(
                reportId: $this->identityProvider->nextIdentity(),
                domainId: $message->domainId,
                xmlContent: $quarantined->decompressedXml(),
                sourceEnvelopeId: $quarantined->receivedEmail->id,
            ));

            // The quarantine row's purpose is fulfilled — drop it. If the
            // downstream ProcessDmarcReport finds the report is a duplicate,
            // it short-circuits, so deleting here is safe.
            $this->entityManager->remove($quarantined);
        }
    }
}

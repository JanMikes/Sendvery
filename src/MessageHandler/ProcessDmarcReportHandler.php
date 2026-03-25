<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Events\DmarcReportProcessed;
use App\Message\ProcessDmarcReport;
use App\Repository\DmarcReportRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dmarc\DmarcXmlParser;
use App\Services\IdentityProvider;
use App\Value\AuthResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ProcessDmarcReportHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private DmarcReportRepository $dmarcReportRepository,
        private DmarcXmlParser $parser,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessDmarcReport $message): void
    {
        $parsed = $this->parser->parse($message->xmlContent);

        if ($this->dmarcReportRepository->existsByExternalId($parsed->reportId, $message->domainId)) {
            return;
        }

        $domain = $this->monitoredDomainRepository->get($message->domainId);
        $now = $this->clock->now();

        $compressedXml = base64_encode(gzcompress($message->xmlContent));

        $report = new DmarcReport(
            id: $message->reportId,
            monitoredDomain: $domain,
            reporterOrg: $parsed->reporterOrg,
            reporterEmail: $parsed->reporterEmail,
            externalReportId: $parsed->reportId,
            dateRangeBegin: $parsed->dateRangeBegin,
            dateRangeEnd: $parsed->dateRangeEnd,
            policyDomain: $parsed->policyDomain,
            policyAdkim: $parsed->policyAdkim,
            policyAspf: $parsed->policyAspf,
            policyP: $parsed->policyP,
            policySp: $parsed->policySp,
            policyPct: $parsed->policyPct,
            rawXml: $compressedXml,
            processedAt: $now,
        );

        $this->entityManager->persist($report);

        $passCount = 0;
        $failCount = 0;

        foreach ($parsed->records as $parsedRecord) {
            $record = new DmarcRecord(
                id: $this->identityProvider->nextIdentity(),
                dmarcReport: $report,
                sourceIp: $parsedRecord->sourceIp,
                count: $parsedRecord->count,
                disposition: $parsedRecord->disposition,
                dkimResult: $parsedRecord->dkimResult,
                spfResult: $parsedRecord->spfResult,
                headerFrom: $parsedRecord->headerFrom,
                dkimDomain: $parsedRecord->dkimDomain,
                dkimSelector: $parsedRecord->dkimSelector,
                spfDomain: $parsedRecord->spfDomain,
            );

            $this->entityManager->persist($record);

            if ($parsedRecord->dkimResult === AuthResult::Pass || $parsedRecord->spfResult === AuthResult::Pass) {
                $passCount += $parsedRecord->count;
            } else {
                $failCount += $parsedRecord->count;
            }
        }

        $report->recordThat(new DmarcReportProcessed(
            reportId: $report->id,
            domainId: $domain->id,
            reporterOrg: $parsed->reporterOrg,
            totalRecords: count($parsed->records),
            passCount: $passCount,
            failCount: $failCount,
        ));
    }
}

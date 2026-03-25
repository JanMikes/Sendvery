<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DmarcReport;
use App\Exceptions\DmarcReportNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class DmarcReportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): DmarcReport
    {
        $report = $this->entityManager->find(DmarcReport::class, $id);

        if (null === $report) {
            throw new DmarcReportNotFound(sprintf('DMARC report with ID "%s" not found.', $id->toString()));
        }

        return $report;
    }

    public function existsByExternalId(string $externalReportId, UuidInterface $domainId): bool
    {
        $count = $this->entityManager->getRepository(DmarcReport::class)->count([
            'externalReportId' => $externalReportId,
            'monitoredDomain' => $domainId->toString(),
        ]);

        return $count > 0;
    }
}

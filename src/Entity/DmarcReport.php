<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'dmarc_report')]
#[ORM\UniqueConstraint(name: 'uniq_dmarc_report_domain_external_id', columns: ['monitored_domain_id', 'external_report_id'])]
final class DmarcReport implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false)]
    public readonly MonitoredDomain $monitoredDomain;

    #[ORM\Column(length: 255)]
    public readonly string $reporterOrg;

    #[ORM\Column(length: 255)]
    public readonly string $reporterEmail;

    #[ORM\Column(length: 255)]
    public readonly string $externalReportId;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $dateRangeBegin;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $dateRangeEnd;

    #[ORM\Column(length: 255)]
    public readonly string $policyDomain;

    #[ORM\Column(type: 'string', enumType: DmarcAlignment::class)]
    public readonly DmarcAlignment $policyAdkim;

    #[ORM\Column(type: 'string', enumType: DmarcAlignment::class)]
    public readonly DmarcAlignment $policyAspf;

    #[ORM\Column(type: 'string', enumType: DmarcPolicy::class)]
    public readonly DmarcPolicy $policyP;

    #[ORM\Column(type: 'string', nullable: true, enumType: DmarcPolicy::class)]
    public readonly ?DmarcPolicy $policySp;

    #[ORM\Column(type: 'integer')]
    public readonly int $policyPct;

    #[ORM\Column(type: 'text')]
    public readonly string $rawXml;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $processedAt;

    public function __construct(
        UuidInterface $id,
        MonitoredDomain $monitoredDomain,
        string $reporterOrg,
        string $reporterEmail,
        string $externalReportId,
        \DateTimeImmutable $dateRangeBegin,
        \DateTimeImmutable $dateRangeEnd,
        string $policyDomain,
        DmarcAlignment $policyAdkim,
        DmarcAlignment $policyAspf,
        DmarcPolicy $policyP,
        ?DmarcPolicy $policySp,
        int $policyPct,
        string $rawXml,
        \DateTimeImmutable $processedAt,
    ) {
        $this->id = $id;
        $this->monitoredDomain = $monitoredDomain;
        $this->reporterOrg = $reporterOrg;
        $this->reporterEmail = $reporterEmail;
        $this->externalReportId = $externalReportId;
        $this->dateRangeBegin = $dateRangeBegin;
        $this->dateRangeEnd = $dateRangeEnd;
        $this->policyDomain = $policyDomain;
        $this->policyAdkim = $policyAdkim;
        $this->policyAspf = $policyAspf;
        $this->policyP = $policyP;
        $this->policySp = $policySp;
        $this->policyPct = $policyPct;
        $this->rawXml = $rawXml;
        $this->processedAt = $processedAt;
    }
}

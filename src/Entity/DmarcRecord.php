<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\AuthResult;
use App\Value\Disposition;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'dmarc_record')]
final class DmarcRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: DmarcReport::class)]
    #[ORM\JoinColumn(name: 'dmarc_report_id', nullable: false)]
    public readonly DmarcReport $dmarcReport;

    #[ORM\Column(length: 45)]
    public readonly string $sourceIp;

    #[ORM\Column(type: 'integer')]
    public readonly int $count;

    #[ORM\Column(type: 'string', enumType: Disposition::class)]
    public readonly Disposition $disposition;

    #[ORM\Column(type: 'string', enumType: AuthResult::class)]
    public readonly AuthResult $dkimResult;

    #[ORM\Column(type: 'string', enumType: AuthResult::class)]
    public readonly AuthResult $spfResult;

    #[ORM\Column(length: 255)]
    public readonly string $headerFrom;

    #[ORM\Column(length: 255, nullable: true)]
    public readonly ?string $dkimDomain;

    #[ORM\Column(length: 255, nullable: true)]
    public readonly ?string $dkimSelector;

    #[ORM\Column(length: 255, nullable: true)]
    public readonly ?string $spfDomain;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $resolvedHostname;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $resolvedOrg;

    public function __construct(
        UuidInterface $id,
        DmarcReport $dmarcReport,
        string $sourceIp,
        int $count,
        Disposition $disposition,
        AuthResult $dkimResult,
        AuthResult $spfResult,
        string $headerFrom,
        ?string $dkimDomain = null,
        ?string $dkimSelector = null,
        ?string $spfDomain = null,
        ?string $resolvedHostname = null,
        ?string $resolvedOrg = null,
    ) {
        $this->id = $id;
        $this->dmarcReport = $dmarcReport;
        $this->sourceIp = $sourceIp;
        $this->count = $count;
        $this->disposition = $disposition;
        $this->dkimResult = $dkimResult;
        $this->spfResult = $spfResult;
        $this->headerFrom = $headerFrom;
        $this->dkimDomain = $dkimDomain;
        $this->dkimSelector = $dkimSelector;
        $this->spfDomain = $spfDomain;
        $this->resolvedHostname = $resolvedHostname;
        $this->resolvedOrg = $resolvedOrg;
    }
}

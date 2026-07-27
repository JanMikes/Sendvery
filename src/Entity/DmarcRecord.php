<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\PolicyOverrideReasonsType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\PolicyOverrideReason;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'dmarc_record')]
final class DmarcRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public UuidInterface $id;

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

    /**
     * Why the receiver did not apply the published policy (RFC 7489 §6.7) —
     * receiver-attested, and therefore unforgeable by the sender.
     *
     * JSON rather than a child table: a record can carry several reasons, so a
     * scalar column is out; but nothing queries them, they are only ever read
     * alongside their parent record, and the cardinality is 0-to-a-few. A child
     * table would buy filterability nobody needs at the price of an extra
     * entity, cascade configuration and N more INSERTs per report on the ingest
     * path. Promoting it later stays a pure data migration.
     *
     * @var list<PolicyOverrideReason>
     */
    #[ORM\Column(type: PolicyOverrideReasonsType::NAME, options: ['default' => '[]'])]
    public readonly array $policyOverrideReasons;

    /**
     * @param list<PolicyOverrideReason> $policyOverrideReasons
     */
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
        array $policyOverrideReasons = [],
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
        $this->policyOverrideReasons = $policyOverrideReasons;
    }
}

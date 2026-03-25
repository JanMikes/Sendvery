<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\DnsCheckCompleted;
use App\Value\DnsCheckType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'dns_check_result')]
#[ORM\Index(name: 'idx_dns_check_domain_type', columns: ['monitored_domain_id', 'type'])]
#[ORM\Index(name: 'idx_dns_check_checked_at', columns: ['checked_at'])]
final class DnsCheckResult implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false)]
    public readonly MonitoredDomain $monitoredDomain;

    #[ORM\Column(type: 'string', enumType: DnsCheckType::class)]
    public readonly DnsCheckType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $checkedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    public readonly ?string $rawRecord;

    #[ORM\Column(type: 'boolean')]
    public readonly bool $isValid;

    /** @var array<array{severity: string, message: string, recommendation?: string}> */
    #[ORM\Column(type: 'json')]
    public readonly array $issues;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public readonly array $details;

    #[ORM\Column(type: 'text', nullable: true)]
    public readonly ?string $previousRawRecord;

    #[ORM\Column(type: 'boolean')]
    public readonly bool $hasChanged;

    /**
     * @param array<array{severity: string, message: string, recommendation?: string}> $issues
     * @param array<string, mixed>                                                     $details
     */
    public function __construct(
        UuidInterface $id,
        MonitoredDomain $monitoredDomain,
        DnsCheckType $type,
        \DateTimeImmutable $checkedAt,
        ?string $rawRecord,
        bool $isValid,
        array $issues,
        array $details,
        ?string $previousRawRecord,
        bool $hasChanged,
    ) {
        $this->id = $id;
        $this->monitoredDomain = $monitoredDomain;
        $this->type = $type;
        $this->checkedAt = $checkedAt;
        $this->rawRecord = $rawRecord;
        $this->isValid = $isValid;
        $this->issues = $issues;
        $this->details = $details;
        $this->previousRawRecord = $previousRawRecord;
        $this->hasChanged = $hasChanged;

        $this->recordThat(new DnsCheckCompleted(
            dnsCheckResultId: $this->id,
            domainId: $this->monitoredDomain->id,
            teamId: $this->monitoredDomain->team->id,
            type: $this->type,
            hasChanged: $this->hasChanged,
            isValid: $this->isValid,
            rawRecord: $this->rawRecord,
            previousRawRecord: $this->previousRawRecord,
        ));
    }
}

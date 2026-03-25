<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'blacklist_check_result')]
#[ORM\Index(name: 'idx_blacklist_check_domain', columns: ['monitored_domain_id'])]
#[ORM\Index(name: 'idx_blacklist_check_domain_ip', columns: ['monitored_domain_id', 'ip_address'])]
final class BlacklistCheckResult
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false)]
    public readonly MonitoredDomain $monitoredDomain;

    #[ORM\Column(length: 45)]
    public readonly string $ipAddress;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $checkedAt;

    /** @var array<string, array{listed: bool, reason: string|null}> */
    #[ORM\Column(type: 'json')]
    public readonly array $results;

    #[ORM\Column(type: 'boolean')]
    public readonly bool $isListed;

    /**
     * @param array<string, array{listed: bool, reason: string|null}> $results
     */
    public function __construct(
        UuidInterface $id,
        MonitoredDomain $monitoredDomain,
        string $ipAddress,
        \DateTimeImmutable $checkedAt,
        array $results,
        bool $isListed,
    ) {
        $this->id = $id;
        $this->monitoredDomain = $monitoredDomain;
        $this->ipAddress = $ipAddress;
        $this->checkedAt = $checkedAt;
        $this->results = $results;
        $this->isListed = $isListed;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'known_sender')]
#[ORM\UniqueConstraint(name: 'uniq_known_sender_domain_ip', columns: ['monitored_domain_id', 'source_ip'])]
#[ORM\Index(name: 'idx_known_sender_domain', columns: ['monitored_domain_id'])]
final class KnownSender
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false)]
    public readonly MonitoredDomain $monitoredDomain;

    #[ORM\Column(length: 45)]
    public readonly string $sourceIp;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $hostname;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $organization;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $label;

    #[ORM\Column(type: 'boolean')]
    public bool $isAuthorized;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'integer')]
    public int $totalMessages;

    #[ORM\Column(type: 'float')]
    public float $passRate;

    public function __construct(
        UuidInterface $id,
        MonitoredDomain $monitoredDomain,
        string $sourceIp,
        \DateTimeImmutable $firstSeenAt,
        \DateTimeImmutable $lastSeenAt,
        int $totalMessages,
        float $passRate,
        ?string $hostname = null,
        ?string $organization = null,
        ?string $label = null,
        bool $isAuthorized = false,
    ) {
        $this->id = $id;
        $this->monitoredDomain = $monitoredDomain;
        $this->sourceIp = $sourceIp;
        $this->hostname = $hostname;
        $this->organization = $organization;
        $this->label = $label;
        $this->isAuthorized = $isAuthorized;
        $this->firstSeenAt = $firstSeenAt;
        $this->lastSeenAt = $lastSeenAt;
        $this->totalMessages = $totalMessages;
        $this->passRate = $passRate;
    }

    public function updateStats(
        \DateTimeImmutable $lastSeenAt,
        int $totalMessages,
        float $passRate,
    ): void {
        $this->lastSeenAt = $lastSeenAt;
        $this->totalMessages = $totalMessages;
        $this->passRate = $passRate;
    }
}

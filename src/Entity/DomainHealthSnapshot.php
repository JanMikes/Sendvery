<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'domain_health_snapshot')]
#[ORM\Index(name: 'idx_health_snapshot_domain', columns: ['monitored_domain_id'])]
#[ORM\Index(name: 'idx_health_snapshot_domain_date', columns: ['monitored_domain_id', 'checked_at'])]
final class DomainHealthSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false)]
    public readonly MonitoredDomain $monitoredDomain;

    #[ORM\Column(length: 1)]
    public readonly string $grade;

    #[ORM\Column(type: 'integer')]
    public readonly int $score;

    #[ORM\Column(type: 'integer')]
    public readonly int $spfScore;

    #[ORM\Column(type: 'integer')]
    public readonly int $dkimScore;

    #[ORM\Column(type: 'integer')]
    public readonly int $dmarcScore;

    #[ORM\Column(type: 'integer')]
    public readonly int $mxScore;

    #[ORM\Column(type: 'integer')]
    public readonly int $blacklistScore;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $checkedAt;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public readonly array $recommendations;

    #[ORM\Column(length: 64, nullable: true)]
    public readonly ?string $shareHash;

    /**
     * @param array<string, mixed> $recommendations
     */
    public function __construct(
        UuidInterface $id,
        MonitoredDomain $monitoredDomain,
        string $grade,
        int $score,
        int $spfScore,
        int $dkimScore,
        int $dmarcScore,
        int $mxScore,
        int $blacklistScore,
        \DateTimeImmutable $checkedAt,
        array $recommendations = [],
        ?string $shareHash = null,
    ) {
        $this->id = $id;
        $this->monitoredDomain = $monitoredDomain;
        $this->grade = $grade;
        $this->score = $score;
        $this->spfScore = $spfScore;
        $this->dkimScore = $dkimScore;
        $this->dmarcScore = $dmarcScore;
        $this->mxScore = $mxScore;
        $this->blacklistScore = $blacklistScore;
        $this->checkedAt = $checkedAt;
        $this->recommendations = $recommendations;
        $this->shareHash = $shareHash;
    }
}

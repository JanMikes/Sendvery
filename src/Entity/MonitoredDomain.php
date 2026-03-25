<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\DomainAdded;
use App\Value\DmarcPolicy;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'monitored_domain')]
#[ORM\UniqueConstraint(name: 'uniq_monitored_domain_team_domain', columns: ['team_id', 'domain'])]
final class MonitoredDomain implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false)]
    public readonly Team $team;

    #[ORM\Column(length: 255)]
    public string $domain;

    #[ORM\Column(type: 'string', nullable: true, enumType: DmarcPolicy::class)]
    public ?DmarcPolicy $dmarcPolicy;

    #[ORM\Column(type: 'boolean')]
    public bool $isVerified;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        Team $team,
        string $domain,
        \DateTimeImmutable $createdAt,
        ?DmarcPolicy $dmarcPolicy = null,
        bool $isVerified = false,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->domain = $domain;
        $this->createdAt = $createdAt;
        $this->dmarcPolicy = $dmarcPolicy;
        $this->isVerified = $isVerified;

        $this->recordThat(new DomainAdded($this->id, $this->team->id));
    }
}

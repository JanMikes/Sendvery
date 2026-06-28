<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Dns\PolicyChangeSource;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Immutable audit row for every managed-DMARC policy change (DEC-058). Written
 * by the RecordManagedDmarcPolicyChange handler on DmarcPolicyChanged, surfaced
 * on the dashboard card's "Recent changes" panel. Because it's an ORM entity,
 * SchemaTool creates it in the test DB — no createMigrationOnlyTables change
 * needed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'managed_dmarc_policy_change')]
#[ORM\Index(name: 'idx_mdpc_domain', columns: ['monitored_domain_id'])]
#[ORM\Index(name: 'idx_mdpc_team', columns: ['team_id'])]
final class ManagedDmarcPolicyChange
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false, onDelete: 'CASCADE')]
    public readonly MonitoredDomain $domain;

    #[ORM\Column(type: 'uuid')]
    public readonly UuidInterface $teamId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    public readonly ?UuidInterface $actorUserId;

    #[ORM\Column(type: 'string', length: 20, enumType: PolicyChangeSource::class)]
    public readonly PolicyChangeSource $source;

    #[ORM\Column(length: 40, nullable: true)]
    public readonly ?string $fromPolicy;

    #[ORM\Column(length: 40)]
    public readonly string $toPolicy;

    #[ORM\Column(type: 'text', nullable: true)]
    public readonly ?string $reason;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        MonitoredDomain $domain,
        UuidInterface $teamId,
        ?UuidInterface $actorUserId,
        PolicyChangeSource $source,
        ?string $fromPolicy,
        string $toPolicy,
        ?string $reason,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->domain = $domain;
        $this->teamId = $teamId;
        $this->actorUserId = $actorUserId;
        $this->source = $source;
        $this->fromPolicy = $fromPolicy;
        $this->toPolicy = $toPolicy;
        $this->reason = $reason;
        $this->createdAt = $createdAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\AlertType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * A forward-only silence for one (team, domain, alert-type) triple.
 *
 * Consulted by AlertEngine::createAlert — if a matching row exists, the
 * incoming alert is dropped (not persisted, no event emitted). Mute is
 * scoped to a domain on purpose: team-wide alerts (mailbox-level events,
 * etc.) can never be muted because they expose a system-level failure
 * the user actually needs to see.
 */
#[ORM\Entity]
#[ORM\Table(name: 'muted_alert')]
#[ORM\Index(name: 'idx_muted_alert_team', columns: ['team_id'])]
#[ORM\UniqueConstraint(name: 'uniq_muted_alert', columns: ['team_id', 'monitored_domain_id', 'alert_type'])]
final class MutedAlert
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false)]
    public readonly Team $team;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: false)]
    public readonly MonitoredDomain $monitoredDomain;

    #[ORM\Column(name: 'alert_type', type: 'string', length: 64, enumType: AlertType::class)]
    public readonly AlertType $alertType;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $mutedAt;

    public function __construct(
        UuidInterface $id,
        Team $team,
        MonitoredDomain $monitoredDomain,
        AlertType $alertType,
        \DateTimeImmutable $mutedAt,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->monitoredDomain = $monitoredDomain;
        $this->alertType = $alertType;
        $this->mutedAt = $mutedAt;
    }
}

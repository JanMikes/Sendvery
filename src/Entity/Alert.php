<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\AlertCreated;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'alert')]
#[ORM\Index(name: 'idx_alert_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_alert_team_unread', columns: ['team_id', 'is_read'])]
#[ORM\Index(name: 'idx_alert_created_at', columns: ['created_at'])]
final class Alert implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false)]
    public readonly Team $team;

    #[ORM\ManyToOne(targetEntity: MonitoredDomain::class)]
    #[ORM\JoinColumn(name: 'monitored_domain_id', nullable: true)]
    public readonly ?MonitoredDomain $monitoredDomain;

    #[ORM\Column(type: 'string', enumType: AlertType::class)]
    public readonly AlertType $type;

    #[ORM\Column(type: 'string', enumType: AlertSeverity::class)]
    public readonly AlertSeverity $severity;

    #[ORM\Column(length: 255)]
    public readonly string $title;

    #[ORM\Column(type: 'text')]
    public readonly string $message;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public readonly array $data;

    #[ORM\Column(type: 'boolean')]
    public bool $isRead;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        UuidInterface $id,
        Team $team,
        ?MonitoredDomain $monitoredDomain,
        AlertType $type,
        AlertSeverity $severity,
        string $title,
        string $message,
        array $data,
        \DateTimeImmutable $createdAt,
        bool $isRead = false,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->monitoredDomain = $monitoredDomain;
        $this->type = $type;
        $this->severity = $severity;
        $this->title = $title;
        $this->message = $message;
        $this->data = $data;
        $this->isRead = $isRead;
        $this->createdAt = $createdAt;

        $this->recordThat(new AlertCreated(
            alertId: $this->id,
            teamId: $this->team->id,
            type: $this->type,
            severity: $this->severity,
            title: $this->title,
            domainName: $this->monitoredDomain?->domain,
        ));
    }

    public function markAsRead(): void
    {
        $this->isRead = true;
    }
}

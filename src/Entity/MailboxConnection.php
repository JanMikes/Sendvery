<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\MailboxConnectionCreated;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'mailbox_connection')]
final class MailboxConnection implements EntityWithEvents
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
    public ?MonitoredDomain $monitoredDomain;

    #[ORM\Column(type: 'string', enumType: MailboxType::class)]
    public readonly MailboxType $type;

    #[ORM\Column(length: 255)]
    public readonly string $host;

    #[ORM\Column(type: 'integer')]
    public readonly int $port;

    #[ORM\Column(type: 'text')]
    public readonly string $encryptedUsername;

    #[ORM\Column(type: 'text')]
    public readonly string $encryptedPassword;

    #[ORM\Column(type: 'string', enumType: MailboxEncryption::class)]
    public readonly MailboxEncryption $encryption;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastPolledAt;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastError;

    #[ORM\Column(type: 'boolean')]
    public bool $isActive;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        Team $team,
        MailboxType $type,
        string $host,
        int $port,
        string $encryptedUsername,
        string $encryptedPassword,
        MailboxEncryption $encryption,
        \DateTimeImmutable $createdAt,
        ?MonitoredDomain $monitoredDomain = null,
        bool $isActive = true,
        ?\DateTimeImmutable $lastPolledAt = null,
        ?string $lastError = null,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->type = $type;
        $this->host = $host;
        $this->port = $port;
        $this->encryptedUsername = $encryptedUsername;
        $this->encryptedPassword = $encryptedPassword;
        $this->encryption = $encryption;
        $this->createdAt = $createdAt;
        $this->monitoredDomain = $monitoredDomain;
        $this->isActive = $isActive;
        $this->lastPolledAt = $lastPolledAt;
        $this->lastError = $lastError;

        $this->recordThat(new MailboxConnectionCreated($this->id, $this->team->id));
    }

    public function markPolled(\DateTimeImmutable $polledAt): void
    {
        $this->lastPolledAt = $polledAt;
        $this->lastError = null;
    }

    public function markError(string $error): void
    {
        $this->lastError = $error;
    }
}

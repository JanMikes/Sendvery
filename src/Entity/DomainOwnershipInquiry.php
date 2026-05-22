<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Record of the user clicking "Notify admin — I'm the real owner" on the
 * "domain already monitored" page. Stored so the admin can audit who asked
 * and so we can rate-limit one inquiry per user/domain/24h.
 */
#[ORM\Entity]
#[ORM\Table(name: 'domain_ownership_inquiry')]
#[ORM\Index(name: 'idx_inquiry_dedupe', columns: ['inquiring_user_id', 'domain', 'created_at'])]
final class DomainOwnershipInquiry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(type: 'text')]
    public readonly string $domain;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'inquiring_user_id', nullable: false, onDelete: 'CASCADE')]
    public readonly User $inquiringUser;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'inquiring_team_id', nullable: false, onDelete: 'CASCADE')]
    public readonly Team $inquiringTeam;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'current_owner_team_id', nullable: false, onDelete: 'CASCADE')]
    public readonly Team $currentOwnerTeam;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $notifiedAt;

    public function __construct(
        UuidInterface $id,
        string $domain,
        User $inquiringUser,
        Team $inquiringTeam,
        Team $currentOwnerTeam,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->domain = strtolower($domain);
        $this->inquiringUser = $inquiringUser;
        $this->inquiringTeam = $inquiringTeam;
        $this->currentOwnerTeam = $currentOwnerTeam;
        $this->createdAt = $createdAt;
        $this->notifiedAt = null;
    }

    public function markNotified(\DateTimeImmutable $now): void
    {
        $this->notifiedAt = $now;
    }
}

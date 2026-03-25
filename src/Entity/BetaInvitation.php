<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\InvitationStatus;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'beta_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_beta_invitation_token', columns: ['invitation_token'])]
#[ORM\Index(name: 'idx_beta_invitation_email', columns: ['email'])]
#[ORM\Index(name: 'idx_beta_invitation_status', columns: ['status'])]
final class BetaInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(length: 255)]
    public readonly string $email;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by_id', nullable: true, onDelete: 'SET NULL')]
    public readonly ?User $invitedBy;

    #[ORM\Column(length: 128, unique: true)]
    public readonly string $invitationToken;

    #[ORM\Column(type: 'string', length: 20, enumType: InvitationStatus::class)]
    public InvitationStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $acceptedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $expiresAt;

    public function __construct(
        UuidInterface $id,
        string $email,
        string $invitationToken,
        \DateTimeImmutable $sentAt,
        \DateTimeImmutable $expiresAt,
        ?User $invitedBy = null,
        InvitationStatus $status = InvitationStatus::Pending,
        ?\DateTimeImmutable $acceptedAt = null,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->invitationToken = $invitationToken;
        $this->sentAt = $sentAt;
        $this->expiresAt = $expiresAt;
        $this->invitedBy = $invitedBy;
        $this->status = $status;
        $this->acceptedAt = $acceptedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->status = InvitationStatus::Accepted;
        $this->acceptedAt = $now;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\TeamInvitationStatus;
use App\Value\TeamRole;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * A pending or historical "join my team on Sendvery" invite. The token is the
 * shareable secret the invitee uses to accept. The `(team_id, invited_email)`
 * partial unique index (on pending rows only) blocks duplicate active invites
 * while still letting an admin re-invite someone after revoking or expiring
 * the previous attempt.
 *
 * Distinct from BetaInvitation: that one grants closed-beta access; this one
 * grants membership in an existing team.
 */
#[ORM\Entity]
#[ORM\Table(name: 'team_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_team_invitation_token', columns: ['invitation_token'])]
#[ORM\Index(name: 'idx_team_invitation_team_status', columns: ['team_id', 'status'])]
final class TeamInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false, onDelete: 'CASCADE')]
    public readonly Team $team;

    #[ORM\Column(length: 255)]
    public readonly string $invitedEmail;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by_id', nullable: false, onDelete: 'CASCADE')]
    public readonly User $invitedBy;

    #[ORM\Column(type: 'string', length: 20, enumType: TeamRole::class)]
    public readonly TeamRole $role;

    #[ORM\Column(length: 128, unique: true)]
    public readonly string $invitationToken;

    #[ORM\Column(type: 'string', length: 20, enumType: TeamInvitationStatus::class)]
    public TeamInvitationStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $acceptedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $revokedAt;

    public function __construct(
        UuidInterface $id,
        Team $team,
        string $invitedEmail,
        User $invitedBy,
        TeamRole $role,
        string $invitationToken,
        \DateTimeImmutable $sentAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->invitedEmail = strtolower(trim($invitedEmail));
        $this->invitedBy = $invitedBy;
        $this->role = $role;
        $this->invitationToken = $invitationToken;
        $this->status = TeamInvitationStatus::Pending;
        $this->sentAt = $sentAt;
        $this->expiresAt = $expiresAt;
        $this->acceptedAt = null;
        $this->revokedAt = null;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function isAcceptable(\DateTimeImmutable $now): bool
    {
        return TeamInvitationStatus::Pending === $this->status && !$this->isExpired($now);
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->status = TeamInvitationStatus::Accepted;
        $this->acceptedAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->status = TeamInvitationStatus::Revoked;
        $this->revokedAt = $now;
    }

    public function resend(\DateTimeImmutable $now, \DateTimeImmutable $newExpiresAt): void
    {
        $this->sentAt = $now;
        $this->expiresAt = $newExpiresAt;
    }
}

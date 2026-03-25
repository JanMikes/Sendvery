<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\TeamRole;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'team_membership')]
#[ORM\UniqueConstraint(name: 'unique_user_team', columns: ['user_id', 'team_id'])]
final class TeamMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public readonly User $user;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: false)]
    public readonly Team $team;

    #[ORM\Column(type: 'string', length: 20, enumType: TeamRole::class)]
    public TeamRole $role;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $joinedAt;

    public function __construct(
        UuidInterface $id,
        User $user,
        Team $team,
        TeamRole $role,
        \DateTimeImmutable $joinedAt,
    ) {
        $this->id = $id;
        $this->user = $user;
        $this->team = $team;
        $this->role = $role;
        $this->joinedAt = $joinedAt;
    }
}

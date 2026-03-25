<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\FeedbackType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'user_feedback')]
#[ORM\Index(name: 'idx_user_feedback_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_user_feedback_created_at', columns: ['created_at'])]
final class UserFeedback
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public readonly User $user;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public readonly Team $team;

    #[ORM\Column(type: 'string', length: 20, enumType: FeedbackType::class)]
    public readonly FeedbackType $type;

    #[ORM\Column(type: 'text')]
    public readonly string $message;

    #[ORM\Column(length: 512)]
    public readonly string $page;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        User $user,
        Team $team,
        FeedbackType $type,
        string $message,
        string $page,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->user = $user;
        $this->team = $team;
        $this->type = $type;
        $this->message = $message;
        $this->page = $page;
        $this->createdAt = $createdAt;
    }
}

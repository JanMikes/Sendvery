<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'magic_link_token')]
final class MagicLinkToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?User $user;

    #[ORM\Column(length: 255)]
    public readonly string $email;

    #[ORM\Column(length: 128, unique: true)]
    public readonly string $token;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $usedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        string $email,
        string $token,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
        ?User $user = null,
        ?\DateTimeImmutable $usedAt = null,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->token = $token;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->user = $user;
        $this->usedAt = $usedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }
}

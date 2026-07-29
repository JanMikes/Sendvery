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
    public UuidInterface $id;

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

    // Forensics for the July 2026 signup-abuse campaign: the request's origin
    // travels with the token so a later investigation can tie a wave of
    // requests to source IPs without needing proxy access logs (Traefik does
    // not retain them long enough). Nullable — tokens created before the
    // column existed, and non-HTTP call sites, have no origin to record.
    #[ORM\Column(length: 45, nullable: true)]
    public readonly ?string $requestedIp;

    #[ORM\Column(length: 512, nullable: true)]
    public readonly ?string $requestedUserAgent;

    public function __construct(
        UuidInterface $id,
        string $email,
        string $token,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
        ?User $user = null,
        ?\DateTimeImmutable $usedAt = null,
        ?string $requestedIp = null,
        ?string $requestedUserAgent = null,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->token = $token;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->user = $user;
        $this->usedAt = $usedAt;
        $this->requestedIp = $requestedIp;
        $this->requestedUserAgent = $requestedUserAgent;
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

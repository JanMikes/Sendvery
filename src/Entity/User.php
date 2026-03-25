<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\UserRegistered;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: '"user"')]
final class User implements EntityWithEvents, UserInterface
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(length: 255, unique: true)]
    public string $email;

    #[ORM\Column(length: 10)]
    public string $locale;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        string $email,
        \DateTimeImmutable $createdAt,
        string $locale = 'en',
        ?\DateTimeImmutable $lastLoginAt = null,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->createdAt = $createdAt;
        $this->locale = $locale;
        $this->lastLoginAt = $lastLoginAt;

        $this->recordThat(new UserRegistered($this->id, $this->email));
    }

    /** @return array<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}

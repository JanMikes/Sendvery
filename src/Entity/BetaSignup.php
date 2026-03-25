<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\BetaSignupCreated;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'beta_signup')]
#[ORM\UniqueConstraint(name: 'uniq_beta_signup_email', columns: ['email'])]
final class BetaSignup implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(length: 255, unique: true)]
    public string $email;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $domainCount;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $painPoint;

    #[ORM\Column(length: 100)]
    public string $source;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $signedUpAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $confirmedAt;

    #[ORM\Column(length: 64)]
    public readonly string $confirmationToken;

    public function __construct(
        UuidInterface $id,
        string $email,
        ?int $domainCount,
        ?string $painPoint,
        string $source,
        \DateTimeImmutable $signedUpAt,
        string $confirmationToken,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->domainCount = $domainCount;
        $this->painPoint = $painPoint;
        $this->source = $source;
        $this->signedUpAt = $signedUpAt;
        $this->confirmedAt = null;
        $this->confirmationToken = $confirmationToken;

        $this->recordThat(new BetaSignupCreated($this->id, $this->email, $this->confirmationToken));
    }

    public function confirm(\DateTimeImmutable $confirmedAt): void
    {
        $this->confirmedAt = $confirmedAt;
    }
}

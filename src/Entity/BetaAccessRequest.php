<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\BetaAccessRequested;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'beta_access_request')]
final class BetaAccessRequest implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public UuidInterface $id;

    #[ORM\Column(length: 255)]
    public readonly string $email;

    #[ORM\Column(length: 255)]
    public readonly string $name;

    #[ORM\Column(length: 255, nullable: true)]
    public readonly ?string $company;

    #[ORM\Column(length: 32, enumType: SubscriptionPlan::class)]
    public readonly SubscriptionPlan $requestedPlan;

    #[ORM\Column(type: 'integer', nullable: true)]
    public readonly ?int $domainCount;

    #[ORM\Column(type: 'text', nullable: true)]
    public readonly ?string $message;

    #[ORM\Column(length: 100)]
    public readonly string $source;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $requestedAt;

    public function __construct(
        UuidInterface $id,
        string $email,
        string $name,
        ?string $company,
        SubscriptionPlan $requestedPlan,
        ?int $domainCount,
        ?string $message,
        string $source,
        \DateTimeImmutable $requestedAt,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->name = $name;
        $this->company = $company;
        $this->requestedPlan = $requestedPlan;
        $this->domainCount = $domainCount;
        $this->message = $message;
        $this->source = $source;
        $this->requestedAt = $requestedAt;

        $this->recordThat(new BetaAccessRequested(
            requestId: $this->id,
            email: $this->email,
            name: $this->name,
            company: $this->company,
            requestedPlan: $this->requestedPlan,
            domainCount: $this->domainCount,
            message: $this->message,
        ));
    }
}

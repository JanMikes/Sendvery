<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\TeamCreated;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'team')]
final class Team implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(length: 255, unique: true)]
    public string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeCustomerId;

    #[ORM\Column(length: 50)]
    public string $plan;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeSubscriptionId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $planWarningAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        UuidInterface $id,
        string $name,
        string $slug,
        \DateTimeImmutable $createdAt,
        ?string $stripeCustomerId = null,
        string $plan = 'free',
        ?string $stripeSubscriptionId = null,
        ?\DateTimeImmutable $planWarningAt = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = $createdAt;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->plan = $plan;
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->planWarningAt = $planWarningAt;

        $this->recordThat(new TeamCreated($this->id));
    }

    public function getSubscriptionPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::from($this->plan);
    }
}

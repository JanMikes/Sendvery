<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\TeamCreated;
use App\Value\BillingInterval;
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
    public UuidInterface $id;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(length: 255, unique: true)]
    public string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeCustomerId;

    #[ORM\Column(length: 50)]
    public string $plan;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $billingInterval;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeSubscriptionId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $planWarningAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $setupChecklistDismissedAt;

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
        ?string $billingInterval = null,
        ?\DateTimeImmutable $setupChecklistDismissedAt = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = $createdAt;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->plan = $plan;
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->planWarningAt = $planWarningAt;
        $this->billingInterval = $billingInterval;
        $this->setupChecklistDismissedAt = $setupChecklistDismissedAt;

        $this->recordThat(new TeamCreated($this->id));
    }

    /**
     * Hide the onboarding checklist for every member of this team. The
     * dismissal is intentionally not cleared on regression — the resolver
     * recomputes visibility every render and overrides the dismissal when
     * a previously-completed DMARC step regresses.
     */
    public function dismissSetupChecklist(\DateTimeImmutable $at): void
    {
        $this->setupChecklistDismissedAt = $at;
    }

    public function getSubscriptionPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::from($this->plan);
    }

    public function getBillingInterval(): ?BillingInterval
    {
        return null !== $this->billingInterval ? BillingInterval::from($this->billingInterval) : null;
    }
}

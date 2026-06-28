<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ManagedDmarcPolicyChange;
use App\Entity\MonitoredDomain;
use App\Events\DmarcPolicyChanged;
use App\Services\IdentityProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the immutable audit row for every managed-DMARC policy change, with
 * the from/to policy labels, the source (manual/guided/auto-ramp/rollback/
 * downgrade-freeze), and the acting user when there is one.
 */
#[AsMessageHandler]
final readonly class RecordManagedDmarcPolicyChange
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DmarcPolicyChanged $event): void
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $this->entityManager->persist(new ManagedDmarcPolicyChange(
            id: $this->identityProvider->nextIdentity(),
            domain: $domain,
            teamId: $event->teamId,
            actorUserId: $event->actorUserId,
            source: $event->source,
            fromPolicy: $event->from?->label(),
            toPolicy: $event->to->label(),
            reason: null,
            createdAt: $this->clock->now(),
        ));
    }
}

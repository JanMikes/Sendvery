<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\ManagedDmarcDanglingDetected;
use App\Services\AlertEngine;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Raises a Critical ManagedDmarcDangling alert when a `_dmarc` CNAME still
 * points at Sendvery but managed DMARC is off (or a verified CNAME vanished).
 * The Critical severity also flows through the existing critical-alert email
 * path, so the customer is told to re-enable or remove the CNAME.
 */
#[AsMessageHandler]
final readonly class NotifyTeamWhenManagedDmarcDangling
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlertEngine $alertEngine,
    ) {
    }

    public function __invoke(ManagedDmarcDanglingDetected $event): void
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::ManagedDmarcDangling,
            severity: AlertType::ManagedDmarcDangling->defaultSeverity(),
            title: sprintf('Action needed: %s’s DMARC record points to Sendvery but isn’t managed', $event->domainName),
            message: sprintf('Your `_dmarc` CNAME for %s still points to Sendvery but managed DMARC is off. Re-enable managed DMARC or remove the CNAME so your DMARC keeps working.', $event->domainName),
        );
    }
}

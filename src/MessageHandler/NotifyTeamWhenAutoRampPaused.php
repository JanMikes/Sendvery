<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\AutoRampPaused;
use App\Services\AlertEngine;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A safety-rail pause is a regression: raise a Critical ManagedDmarcRegression
 * alert, which also flows through the existing critical-alert email path. A
 * customer-initiated pause carries the user-pause reason and is skipped (they
 * did it on purpose — no "we paused your ramp" scare).
 */
#[AsMessageHandler]
final readonly class NotifyTeamWhenAutoRampPaused
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlertEngine $alertEngine,
    ) {
    }

    public function __invoke(AutoRampPaused $event): void
    {
        if (ConfigureDmarcAutoRampHandler::USER_PAUSE_REASON === $event->reason) {
            return;
        }

        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::ManagedDmarcRegression,
            severity: AlertType::ManagedDmarcRegression->defaultSeverity(),
            title: sprintf('We paused DMARC enforcement on %s', $event->domainName),
            message: sprintf('Sendvery held your DMARC ramp for %s and won’t tighten until it recovers. Reason: %s', $event->domainName, $event->reason),
            data: ['reason' => $event->reason],
        );
    }
}

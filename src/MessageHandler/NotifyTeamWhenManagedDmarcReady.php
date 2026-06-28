<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\ManagedDmarcBecameReady;
use App\Services\AlertEngine;
use App\Services\Mail\ManagedDmarcMailer;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "You're ready to advance" — sent (guided mode only) when a domain becomes
 * eligible to tighten. A dedicated informational email + an info
 * ManagedDmarcReady alert nudge the customer to advance in one click.
 */
#[AsMessageHandler]
final readonly class NotifyTeamWhenManagedDmarcReady
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlertEngine $alertEngine,
        private ManagedDmarcMailer $mailer,
    ) {
    }

    public function __invoke(ManagedDmarcBecameReady $event): void
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $tier = $event->recommendedTier->value;
        $body = sprintf('Your mail for %s has passed DMARC consistently — you’re ready to advance to %s. Advance in one click whenever you’re ready, or turn on auto-drive to let Sendvery handle it.', $event->domainName, $tier);

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::ManagedDmarcReady,
            severity: AlertType::ManagedDmarcReady->defaultSeverity(),
            title: sprintf('%s is ready for %s', $event->domainName, $tier),
            message: $body,
        );

        $this->mailer->send(
            teamId: $event->teamId->toString(),
            domainId: $event->domainId,
            domainName: $event->domainName,
            pretitle: 'Managed DMARC',
            subject: sprintf('%s is ready for %s', $event->domainName, $tier),
            heading: sprintf('%s is ready to advance', $event->domainName),
            body: $body,
            ctaLabel: 'Advance now',
        );
    }
}

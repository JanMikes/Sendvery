<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\DmarcPolicyChanged;
use App\Services\AlertEngine;
use App\Services\Mail\ManagedDmarcMailer;
use App\Value\AlertType;
use App\Value\DmarcPolicy;
use App\Value\Dns\ManagedDmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Your DMARC is now at quarantine/reject" — sent when an enforcing tier is
 * actually published by a tightening change (guided or auto-ramp). Skips
 * loosening (rollback/freeze) and same-tier republishes. Sends a dedicated
 * informational email + an info ManagedDmarcAdvanced alert.
 */
#[AsMessageHandler]
final readonly class NotifyTeamWhenPolicyAdvanced
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlertEngine $alertEngine,
        private ManagedDmarcMailer $mailer,
    ) {
    }

    public function __invoke(DmarcPolicyChanged $event): void
    {
        if (!$this->isTightenedToEnforcement($event->from, $event->to)) {
            return;
        }

        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $tier = $event->to->p->value;
        $isReject = DmarcPolicy::Reject === $event->to->p;
        $body = $isReject
            ? sprintf('We’ve moved your DMARC policy for %s to full enforcement (reject). Your domain is now fully protected against spoofing.', $event->domainName)
            : sprintf('We’ve moved your DMARC policy for %s to quarantine. Reject is the final step — we’ll continue when your mail is consistently passing.', $event->domainName);

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::ManagedDmarcAdvanced,
            severity: AlertType::ManagedDmarcAdvanced->defaultSeverity(),
            title: sprintf('%s is now at %s', $event->domainName, $tier),
            message: $body,
        );

        $this->mailer->send(
            teamId: $event->teamId->toString(),
            domainId: $event->domainId,
            domainName: $event->domainName,
            pretitle: 'Auto-drive',
            subject: sprintf('%s is now at %s', $event->domainName, $tier),
            heading: sprintf('%s advanced to %s', $event->domainName, $tier),
            body: $body,
            ctaLabel: 'View domain',
        );
    }

    private function isTightenedToEnforcement(?ManagedDmarcPolicy $from, ManagedDmarcPolicy $to): bool
    {
        if (DmarcPolicy::None === $to->p) {
            return false;
        }

        return $this->rank($to->p) > $this->rank($from->p ?? DmarcPolicy::None);
    }

    private function rank(DmarcPolicy $policy): int
    {
        return match ($policy) {
            DmarcPolicy::None => 0,
            DmarcPolicy::Quarantine => 1,
            DmarcPolicy::Reject => 2,
        };
    }
}

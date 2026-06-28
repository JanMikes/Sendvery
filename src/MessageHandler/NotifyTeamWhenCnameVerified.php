<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\CnameVerified;
use App\Services\Mail\ManagedDmarcMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Managed DMARC is live" — sent the first time the CNAME is confirmed to
 * resolve to Sendvery. Email-only (informational); the generic critical-alert
 * mailer would drop it.
 */
#[AsMessageHandler]
final readonly class NotifyTeamWhenCnameVerified
{
    public function __construct(
        private ManagedDmarcMailer $mailer,
    ) {
    }

    public function __invoke(CnameVerified $event): void
    {
        $this->mailer->send(
            teamId: $event->teamId->toString(),
            domainId: $event->domainId,
            domainName: $event->domainName,
            pretitle: 'Managed DMARC',
            subject: sprintf('Managed DMARC is live for %s', $event->domainName),
            heading: 'Managed DMARC is live',
            body: sprintf('We can see your CNAME — Sendvery is now hosting your DMARC policy for %s in monitor-only mode (p=none), so nothing changes for your senders yet. We’ll safely move you toward full enforcement as your mail proves out.', $event->domainName),
            ctaLabel: 'View domain',
        );
    }
}

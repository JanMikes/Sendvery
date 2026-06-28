<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\AutoRampAdvanceScheduled;
use App\Services\Mail\ManagedDmarcMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The 48-hour advance notice — sent when the auto-ramp schedules the next
 * tightening, so the customer can pause if they need to hold off. Email-only.
 */
#[AsMessageHandler]
final readonly class NotifyTeamWhenAutoRampScheduled
{
    public function __construct(
        private ManagedDmarcMailer $mailer,
    ) {
    }

    public function __invoke(AutoRampAdvanceScheduled $event): void
    {
        $tier = $event->to->value;
        $date = $event->scheduledAt->format('j M Y, H:i');

        $this->mailer->send(
            teamId: $event->teamId->toString(),
            domainId: $event->domainId,
            domainName: $event->domainName,
            pretitle: 'Auto-drive',
            subject: sprintf('Heads up: %s moves to %s in 48 hours', $event->domainName, $tier),
            heading: sprintf('%s tightens to %s soon', $event->domainName, $tier),
            body: sprintf('Your DMARC enforcement for %s will tighten to %s on %s. Everything looks ready — but if you need to hold off, open the domain and pause the ramp before then.', $event->domainName, $tier, $date),
            ctaLabel: 'Review or pause',
        );
    }
}

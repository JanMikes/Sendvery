<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DowngradeTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Value\SubscriptionPlan;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DowngradeTeamPlanHandler
{
    /** Pause reason recorded when a downgrade freezes managed DMARC — NOT a regression, so no regression alert/email fires. */
    public const string FREEZE_REASON = 'Plan downgraded — managed DMARC frozen';

    public function __construct(
        private TeamRepository $teamRepository,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DowngradeTeamPlan $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $team->plan = SubscriptionPlan::Free->value;
        $team->stripeSubscriptionId = null;
        $team->planWarningAt = null;
        $team->billingInterval = null;

        // DEC-058: freeze managed DMARC — pause auto-ramp on every managed domain
        // but NEVER loosen the live policy. The card switches read-only; the
        // customer keeps their current protection and can re-upgrade to resume.
        $now = $this->clock->now();
        foreach ($this->monitoredDomainRepository->findManagedDomainsForTeam($message->teamId) as $domain) {
            $domain->pauseAutoRamp(self::FREEZE_REASON, $now);
        }
    }
}

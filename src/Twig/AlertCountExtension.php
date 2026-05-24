<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Query\GetAlerts;
use App\Services\DashboardContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes `unread_alert_count` and `critical_alert_count` Twig globals so the
 * sidebar nav can render a two-tier "N waiting" badge next to the Alerts link:
 * red when at least one critical unread alert is pending, yellow when only
 * non-critical unread alerts remain, hidden when zero.
 *
 * The counts are wrapped in a defensive try/catch: the layout is also
 * rendered on unauth/onboarding pages where DashboardContext throws (no user,
 * no memberships). We treat those as "0" instead of hard-failing the page.
 */
final class AlertCountExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly DashboardContext $dashboardContext,
        private readonly GetAlerts $getAlerts,
    ) {
    }

    /** @return array<string, int> */
    public function getGlobals(): array
    {
        return [
            'unread_alert_count' => $this->resolveUnreadCount(),
            'critical_alert_count' => $this->resolveCriticalCount(),
        ];
    }

    private function resolveUnreadCount(): int
    {
        if (!$this->security->getUser() instanceof User) {
            return 0;
        }

        try {
            $teamId = $this->dashboardContext->getTeamId();
        } catch (\RuntimeException) {
            return 0;
        }

        return $this->getAlerts->countUnreadForTeams([$teamId->toString()]);
    }

    private function resolveCriticalCount(): int
    {
        if (!$this->security->getUser() instanceof User) {
            return 0;
        }

        try {
            $teamId = $this->dashboardContext->getTeamId();
        } catch (\RuntimeException) {
            return 0;
        }

        return $this->getAlerts->countUnreadCriticalForTeams([$teamId->toString()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Query\GetQuarantineList;
use App\Services\DashboardContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the `quarantine_count` Twig global so the sidebar nav can render a
 * "N waiting" badge next to the Quarantine link without every dashboard
 * controller having to pass the variable.
 *
 * The count is wrapped in a defensive try/catch: the layout is also rendered
 * on unauth/onboarding pages where DashboardContext throws (no user, no
 * memberships). We treat those as "0" instead of hard-failing the page.
 */
final class QuarantineCountExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly DashboardContext $dashboardContext,
        private readonly GetQuarantineList $getQuarantineList,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'quarantine_count' => $this->resolveCount(),
        ];
    }

    private function resolveCount(): int
    {
        if (!$this->security->getUser() instanceof User) {
            return 0;
        }

        try {
            $teamId = $this->dashboardContext->getTeamId();
        } catch (\RuntimeException) {
            return 0;
        }

        return $this->getQuarantineList->countForTeam($teamId->toString());
    }
}

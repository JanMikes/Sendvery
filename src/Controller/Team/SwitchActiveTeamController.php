<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Persists the chosen active team into the session, then bounces back to
 * wherever the user came from (or to the dashboard, as a safe default).
 * Throwing on a non-member team rather than silently ignoring it matches
 * the security posture used elsewhere: the route is reachable, but the
 * target team is treated as "doesn't exist for you" → 404.
 */
final class SwitchActiveTeamController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
    ) {
    }

    #[Route('/app/team/switch', name: 'team_switch_active', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $rawTeamId = trim($request->request->getString('team_id'));

        try {
            $teamId = Uuid::fromString($rawTeamId);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Team not found.');
        }

        try {
            $this->dashboardContext->setActiveTeam($teamId);
        } catch (\DomainException) {
            throw $this->createNotFoundException('Team not found.');
        }

        return $this->redirect($this->resolveReturnTo($request));
    }

    /**
     * The switcher posts a `return_to` so a user clicking "switch" on /app/team
     * lands back on /app/team (now showing the new team), not on /app. Only
     * allow same-origin paths under /app/* to avoid open-redirect.
     */
    private function resolveReturnTo(Request $request): string
    {
        $returnTo = trim($request->request->getString('return_to'));

        if ('' !== $returnTo && str_starts_with($returnTo, '/app')) {
            return $returnTo;
        }

        return $this->generateUrl('dashboard_overview');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAlerts;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListAlertsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetAlerts $getAlerts,
    ) {
    }

    #[Route('/app/alerts', name: 'dashboard_alerts')]
    public function __invoke(Request $request): Response
    {
        $teamId = $this->dashboardContext->getTeamId()->toString();

        $severity = $request->query->get('severity');
        $type = $request->query->get('type');
        $domainId = $request->query->get('domain');
        $readFilter = $request->query->get('read');

        $isRead = match ($readFilter) {
            'true' => true,
            'false' => false,
            default => null,
        };

        $alerts = $this->getAlerts->forTeam(
            teamId: $teamId,
            severity: is_string($severity) ? $severity : null,
            type: is_string($type) ? $type : null,
            domainId: is_string($domainId) ? $domainId : null,
            isRead: $isRead,
        );

        $unreadCount = $this->getAlerts->countUnreadForTeam($teamId);

        return $this->render('dashboard/alerts.html.twig', [
            'alerts' => $alerts,
            'unreadCount' => $unreadCount,
            'currentSeverity' => $severity,
            'currentType' => $type,
            'currentRead' => $readFilter,
        ]);
    }
}

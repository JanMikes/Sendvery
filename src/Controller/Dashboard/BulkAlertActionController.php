<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\BulkMarkAlertsRead;
use App\Message\BulkSnoozeAlerts;
use App\Message\MarkAllAlertsRead;
use App\Query\GetAlerts;
use App\Services\DashboardContext;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class BulkAlertActionController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly GetAlerts $getAlerts,
    ) {
    }

    #[Route('/app/alerts/bulk', name: 'dashboard_alerts_bulk', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_alert_action', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['mark_read', 'snooze_7d', 'mark_all_read'], true)) {
            throw $this->createNotFoundException('Unknown bulk action.');
        }

        $teamId = $this->dashboardContext->getTeamId();

        // "Mark all as read" is deliberately selection-independent: the list is
        // capped at 50 rows, so requiring a selection would make it impossible
        // to clear the tail of a busy backlog.
        if ('mark_all_read' === $action) {
            // Counted BEFORE dispatch so the flash reports the alerts actually
            // affected. The per-row actions report their submitted count, which
            // over-reports when an id is already read or belongs elsewhere.
            $affected = $this->getAlerts->countUnreadForTeams([$teamId->toString()], includeSnoozed: true);

            if (0 === $affected) {
                $this->addFlash('success', 'No unread alerts to mark.');

                return $this->redirectToRoute('dashboard_alerts');
            }

            $this->commandBus->dispatch(new MarkAllAlertsRead(teamId: $teamId));
            $this->addFlash('success', sprintf('Marked %d alert%s as read.', $affected, 1 === $affected ? '' : 's'));

            return $this->redirectToRoute('dashboard_alerts');
        }

        /** @var array<int, mixed> $rawIds */
        $rawIds = $request->request->all('alertIds');

        /** @var list<UuidInterface> $alertIds */
        $alertIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId) || !Uuid::isValid($rawId)) {
                continue;
            }
            $alertIds[] = Uuid::fromString($rawId);
        }

        if ([] === $alertIds) {
            return $this->redirectToRoute('dashboard_alerts');
        }

        if ('mark_read' === $action) {
            $this->commandBus->dispatch(new BulkMarkAlertsRead(
                alertIds: $alertIds,
                teamId: $teamId,
            ));
            $this->addFlash('success', sprintf('Marked %d alert%s as read.', count($alertIds), 1 === count($alertIds) ? '' : 's'));
        } else {
            $snoozedUntil = $this->clock->now()->modify('+7 days');
            $this->commandBus->dispatch(new BulkSnoozeAlerts(
                alertIds: $alertIds,
                teamId: $teamId,
                snoozedUntil: $snoozedUntil,
            ));
            $this->addFlash('success', sprintf('Snoozed %d alert%s for 7 days.', count($alertIds), 1 === count($alertIds) ? '' : 's'));
        }

        return $this->redirectToRoute('dashboard_alerts');
    }
}

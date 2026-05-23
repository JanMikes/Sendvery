<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\BulkMarkAlertsRead;
use App\Message\BulkSnoozeAlerts;
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
    ) {
    }

    #[Route('/app/alerts/bulk', name: 'dashboard_alerts_bulk', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_alert_action', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['mark_read', 'snooze_7d'], true)) {
            throw $this->createNotFoundException('Unknown bulk action.');
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

        $teamId = $this->dashboardContext->getTeamId();

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

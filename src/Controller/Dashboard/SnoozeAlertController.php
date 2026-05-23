<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\SnoozeAlert;
use App\Repository\AlertRepository;
use App\Services\DashboardContext;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SnoozeAlertController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly AlertRepository $alertRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/alerts/{id}/snooze', name: 'dashboard_alert_snooze', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('snooze_alert', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $alert = $this->alertRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $alert) {
            throw $this->createNotFoundException('Alert not found.');
        }

        // Whitelist days to {1, 7, 30}. Default to 7 (the toolbar action).
        $days = match ($request->request->getInt('days')) {
            1 => 1,
            30 => 30,
            default => 7,
        };

        $snoozedUntil = $this->clock->now()->modify(sprintf('+%d days', $days));

        $this->commandBus->dispatch(new SnoozeAlert(
            alertId: $alert->id,
            snoozedUntil: $snoozedUntil,
        ));

        $this->addFlash('success', sprintf('Alert snoozed for %d day%s.', $days, 1 === $days ? '' : 's'));

        return $this->redirectToRoute('dashboard_alert_detail', ['id' => $alert->id->toString()]);
    }
}

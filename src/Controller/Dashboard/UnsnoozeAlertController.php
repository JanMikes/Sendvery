<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\UnsnoozeAlert;
use App\Repository\AlertRepository;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UnsnoozeAlertController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly AlertRepository $alertRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/alerts/{id}/unsnooze', name: 'dashboard_alert_unsnooze', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('unsnooze_alert', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $alert = $this->alertRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $alert) {
            throw $this->createNotFoundException('Alert not found.');
        }

        $this->commandBus->dispatch(new UnsnoozeAlert(alertId: $alert->id));

        $this->addFlash('success', 'Snooze removed.');

        return $this->redirectToRoute('dashboard_alert_detail', ['id' => $alert->id->toString()]);
    }
}

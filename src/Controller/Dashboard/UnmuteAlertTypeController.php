<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\UnmuteAlertType;
use App\Repository\MutedAlertRepository;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UnmuteAlertTypeController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MutedAlertRepository $mutedAlertRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/muted-alerts/{id}/unmute', name: 'dashboard_alert_unmute', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('unmute_alert', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $muted = $this->mutedAlertRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $muted) {
            throw $this->createNotFoundException('Muted alert not found.');
        }

        $this->commandBus->dispatch(new UnmuteAlertType(mutedAlertId: $muted->id));

        $this->addFlash('success', 'Alert type unmuted. Future alerts will appear in your list again.');

        return $this->redirectToRoute('dashboard_preferences');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\MuteAlertType;
use App\Repository\AlertRepository;
use App\Repository\MutedAlertRepository;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class MuteAlertTypeController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly AlertRepository $alertRepository,
        private readonly MutedAlertRepository $mutedAlertRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
    ) {
    }

    #[Route('/app/alerts/{id}/mute', name: 'dashboard_alert_mute', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mute_alert', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $alert = $this->alertRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $alert) {
            throw $this->createNotFoundException('Alert not found.');
        }

        // Domain-less alerts (mailbox connection errors, system-wide events)
        // CANNOT be muted — silencing them would hide a real failure surface.
        if (null === $alert->monitoredDomain) {
            $this->addFlash('error', 'Team-wide alerts cannot be muted.');

            return $this->redirectToRoute('dashboard_alert_detail', ['id' => $alert->id->toString()]);
        }

        // Idempotent on the controller side too: if a mute already exists
        // for this (team, domain, type), surface success rather than dispatching.
        $existing = $this->mutedAlertRepository->findOneForTeamDomainType(
            $alert->team->id->toString(),
            $alert->monitoredDomain->id->toString(),
            $alert->type,
        );

        if (null !== $existing) {
            $this->addFlash('success', 'This alert type is already muted for this domain.');

            return $this->redirectToRoute('dashboard_alert_detail', ['id' => $alert->id->toString()]);
        }

        $this->commandBus->dispatch(new MuteAlertType(
            mutedAlertId: $this->identityProvider->nextIdentity(),
            teamId: $alert->team->id,
            domainId: $alert->monitoredDomain->id,
            alertType: $alert->type,
        ));

        $this->addFlash('success', 'Future alerts of this type for this domain will be muted.');

        return $this->redirectToRoute('dashboard_alert_detail', ['id' => $alert->id->toString()]);
    }
}

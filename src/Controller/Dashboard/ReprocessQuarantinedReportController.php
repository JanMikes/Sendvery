<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\ReprocessQuarantinedReport;
use App\Query\GetQuarantineDetail;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ReprocessQuarantinedReportController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetQuarantineDetail $getQuarantineDetail,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/quarantine/{id}/reprocess', name: 'dashboard_quarantine_reprocess', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('quarantine_reprocess', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $teamId = $this->dashboardContext->getTeamId();

        // 404-guarded team-scoped lookup before dispatching — same pattern as
        // ShowReportDetailController so the existence of cross-tenant rows
        // isn't leaked through a 200 response.
        $item = $this->getQuarantineDetail->forTeam($id, $teamId->toString());
        if (null === $item) {
            throw $this->createNotFoundException('Quarantined report not found.');
        }

        $this->commandBus->dispatch(new ReprocessQuarantinedReport(
            quarantineId: Uuid::fromString($id),
            teamId: $teamId,
        ));

        $this->addFlash('success', 'Quarantined report queued for reprocessing.');

        return $this->redirectToRoute('dashboard_quarantine');
    }
}

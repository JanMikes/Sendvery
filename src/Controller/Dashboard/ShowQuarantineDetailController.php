<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetQuarantineDetail;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowQuarantineDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetQuarantineDetail $getQuarantineDetail,
    ) {
    }

    #[Route('/app/quarantine/{id}', name: 'dashboard_quarantine_detail', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $item = $this->getQuarantineDetail->forTeam($id, $teamId->toString());

        if (null === $item) {
            throw $this->createNotFoundException('Quarantined report not found.');
        }

        return $this->render('dashboard/quarantine_detail.html.twig', [
            'item' => $item,
        ]);
    }
}

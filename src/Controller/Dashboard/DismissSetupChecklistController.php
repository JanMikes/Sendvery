<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\TeamRepository;
use App\Services\DashboardContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hide the dashboard onboarding setup checklist for every member of the
 * currently-active team. Persisted on `team.setup_checklist_dismissed_at`
 * so the dismissal is shared. Regression on a previously-completed DMARC
 * step re-surfaces the checklist via the resolver — we never clear the
 * dismissal column.
 */
final class DismissSetupChecklistController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/setup-checklist/dismiss', name: 'dashboard_setup_checklist_dismiss', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('setup_checklist_dismiss', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $team = $this->teamRepository->get($this->dashboardContext->getTeamId());
        $team->dismissSetupChecklist($this->clock->now());

        $this->entityManager->flush();

        return $this->redirectToRoute('dashboard_overview');
    }
}

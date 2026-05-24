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
 * Hide the dashboard's DNS-first "Publish a DMARC RUA record" next-step for
 * every member of the currently-active team. Persisted on
 * `team.ingestion_recommendation_dismissed_at` so the dismissal is shared.
 * After dismissal the NextActionResolver promotes the demoted fallback
 * "Connect a mailbox (fallback)" branch — see TASK-091.
 */
final class DismissIngestionRecommendationController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/ingestion-recommendation/dismiss', name: 'dashboard_ingestion_recommendation_dismiss', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ingestion_recommendation_dismiss', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $team = $this->teamRepository->get($this->dashboardContext->getTeamId());
        $team->dismissIngestionRecommendation($this->clock->now());

        $this->entityManager->flush();

        return $this->redirectToRoute('dashboard_overview');
    }
}

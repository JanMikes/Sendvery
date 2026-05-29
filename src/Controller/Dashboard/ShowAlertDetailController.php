<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\MarkAlertAsRead;
use App\Query\GetAlertDetail;
use App\Repository\AiInsightRepository;
use App\Repository\MutedAlertRepository;
use App\Services\Ai\AiInsightCacheKey;
use App\Services\Ai\AiInsightContent;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\DashboardContext;
use App\Value\AlertType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ShowAlertDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetAlertDetail $getAlertDetail,
        private readonly MutedAlertRepository $mutedAlertRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly AiInsightRepository $insights,
    ) {
    }

    #[Route('/app/alerts/{id}', name: 'dashboard_alert_detail')]
    public function __invoke(string $id): Response
    {
        $alert = $this->getAlertDetail->forAlert($id, $this->dashboardContext->getTeamIdStrings());

        if (null === $alert) {
            throw $this->createNotFoundException('Alert not found.');
        }

        if (!$alert->isRead) {
            $this->commandBus->dispatch(new MarkAlertAsRead(
                alertId: Uuid::fromString($id),
            ));
        }

        // Find any existing mute for the (team, domain, type) so the template
        // can offer Unmute instead of Mute. Skipped for domain-less alerts
        // since those can never be muted. The alert is already team-scoped via
        // the query above, so any team the user belongs to that has a matching
        // mute for this domain+type is the right one.
        $existingMute = null;
        if (null !== $alert->domainId) {
            foreach ($this->dashboardContext->getTeamIdStrings() as $teamId) {
                $candidate = $this->mutedAlertRepository->findOneForTeamDomainType(
                    $teamId,
                    $alert->domainId,
                    AlertType::from($alert->type),
                );
                if (null !== $candidate) {
                    $existingMute = $candidate;

                    break;
                }
            }
        }

        return $this->render('dashboard/alert_detail.html.twig', [
            'alert' => $alert,
            'existingMute' => $existingMute,
            'aiAnomaly' => $this->anomalyInsight($alert->type, $alert->data),
        ]);
    }

    /**
     * Failure-spike alerts pre-compute an AI explanation (async, keyed by the
     * report that tripped the spike). Surface it when present; absence is fine —
     * the worker may still be running, or the team has no AI plan.
     *
     * @param array<string, mixed> $data
     */
    private function anomalyInsight(string $type, array $data): ?AnomalyExplanationResult
    {
        if (AlertType::FailureSpike !== AlertType::from($type)) {
            return null;
        }

        $reportId = $data['report_id'] ?? null;
        if (!is_string($reportId)) {
            return null;
        }

        $cached = $this->insights->findByCacheKey(AiInsightCacheKey::anomalyExplanation($reportId));

        return null !== $cached ? AiInsightContent::anomaly($cached->content) : null;
    }
}

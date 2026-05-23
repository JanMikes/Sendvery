<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\AddDomain;
use App\Message\ReleaseQuarantinedReportsForDomain;
use App\Query\GetQuarantineDetail;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use App\Value\Reports\QuarantineReason;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One-click "Add this domain" action for `unknown_domain` quarantine rows.
 * Replicates the conflict/limit guards from {@see AddDomainController}, then
 * dispatches both `AddDomain` and `ReleaseQuarantinedReportsForDomain` so the
 * parked report flows into the dashboard immediately after the domain lands.
 */
final class AddDomainFromQuarantineController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetQuarantineDetail $getQuarantineDetail,
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly PlanEnforcement $planEnforcement,
        private readonly GetTeamPlan $getTeamPlan,
    ) {
    }

    #[Route('/app/quarantine/{id}/add-domain', name: 'dashboard_quarantine_add_domain', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('quarantine_add_domain', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $teamId = $this->dashboardContext->getTeamId();
        $item = $this->getQuarantineDetail->forTeam($id, $teamId->toString());
        if (null === $item) {
            throw $this->createNotFoundException('Quarantined report not found.');
        }

        // Only `unknown_domain` rows produce an "add this domain" CTA — for
        // `unverified_domain` / `plan_overage` the domain already exists, so
        // this action is meaningless.
        if (QuarantineReason::UnknownDomain->value !== $item->reason) {
            throw $this->createNotFoundException('This quarantine row is not for an unknown domain.');
        }

        $domainName = $item->domainName;

        // Handle the race where another tab (or the user themselves) already
        // added this domain between page load and submit. The plan-limit
        // check is intentionally deferred until after this — releasing a
        // pre-existing domain doesn't add anything, so it must always be
        // allowed even when the team is otherwise at its domain cap.
        $existing = $this->monitoredDomainRepository->findAnyByName($domainName);
        if (null !== $existing) {
            if (!$existing->team->id->equals($teamId)) {
                // Some other team claimed the domain in the meantime.
                return $this->redirectToRoute('domain_taken', ['domain' => $domainName]);
            }

            // Already ours — just release the parked reports against the
            // existing domain.
            $this->commandBus->dispatch(new ReleaseQuarantinedReportsForDomain(
                domainId: $existing->id,
                domainName: $domainName,
            ));

            $this->addFlash('success', sprintf('Released quarantined reports for %s.', $domainName));

            return $this->redirectToRoute('dashboard_domain_detail', ['id' => $existing->id->toString()]);
        }

        $plan = $this->getTeamPlan->forTeam($teamId->toString());
        if (!$this->planEnforcement->canAddDomain($teamId->toString(), $plan)) {
            $this->addFlash('error', 'You have reached your domain limit. Upgrade your plan to add more domains.');

            return $this->redirectToRoute('dashboard_quarantine_detail', ['id' => $id]);
        }

        $domainId = $this->identityProvider->nextIdentity();

        $this->commandBus->dispatch(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: $domainName,
        ));

        $this->commandBus->dispatch(new ReleaseQuarantinedReportsForDomain(
            domainId: $domainId,
            domainName: $domainName,
        ));

        $this->addFlash('success', sprintf('%s added — releasing quarantined reports.', $domainName));

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $domainId->toString()]);
    }
}

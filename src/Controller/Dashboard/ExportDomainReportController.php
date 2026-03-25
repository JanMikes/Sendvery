<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainHealthHistory;
use App\Query\GetDomainReportData;
use App\Query\GetSenderInventory;
use App\Query\GetTeamPlan;
use App\Services\DashboardContext;
use App\Services\PdfReportGenerator;
use App\Services\Stripe\PlanEnforcement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExportDomainReportController extends AbstractController
{
    public function __construct(
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainReportData $getDomainReportData,
        private readonly GetDomainHealthHistory $getDomainHealthHistory,
        private readonly GetSenderInventory $getSenderInventory,
        private readonly PdfReportGenerator $pdfReportGenerator,
        private readonly DashboardContext $dashboardContext,
        private readonly PlanEnforcement $planEnforcement,
        private readonly GetTeamPlan $getTeamPlan,
    ) {
    }

    #[Route('/app/domains/{id}/export/pdf', name: 'dashboard_export_domain_pdf')]
    public function __invoke(string $id): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $plan = $this->getTeamPlan->forTeam($teamId->toString());

        if (!$this->planEnforcement->canAccessFeature($plan, 'pdf_export')) {
            $this->addFlash('error', 'PDF export requires a Personal plan or higher.');

            return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
        }

        $domain = $this->getDomainDetail->forDomain($id);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $reportData = $this->getDomainReportData->forDomain($id);
        $healthSnapshot = $this->getDomainHealthHistory->latestForDomain($id);
        $senders = $this->getSenderInventory->forDomain($id);

        $pdfContent = $this->pdfReportGenerator->generate([
            'domain' => $domain,
            'reportData' => $reportData,
            'healthSnapshot' => $healthSnapshot,
            'senders' => array_slice($senders, 0, 20),
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="sendvery-report-%s.pdf"', $domain->domainName),
        ]);
    }
}

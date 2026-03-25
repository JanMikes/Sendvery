<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainReports;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListDomainReportsController extends AbstractController
{
    public function __construct(
        private readonly GetDomainReports $getDomainReports,
    ) {
    }

    #[Route('/app/domains/{id}/reports', name: 'dashboard_domain_reports')]
    public function __invoke(Request $request, string $id): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $reports = $this->getDomainReports->forDomain($id, limit: $limit, offset: $offset);

        $template = $request->headers->has('Turbo-Frame')
            ? 'dashboard/_domain_reports_table.html.twig'
            : 'dashboard/domain_reports.html.twig';

        return $this->render($template, [
            'reports' => $reports,
            'domainId' => $id,
            'currentPage' => $page,
            'hasNextPage' => count($reports) === $limit,
        ]);
    }
}

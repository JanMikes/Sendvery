<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetReportDetail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowReportDetailController extends AbstractController
{
    public function __construct(
        private readonly GetReportDetail $getReportDetail,
    ) {
    }

    #[Route('/app/reports/{id}', name: 'dashboard_report_detail')]
    public function __invoke(string $id): Response
    {
        $report = $this->getReportDetail->forReport($id);

        if (null === $report) {
            throw $this->createNotFoundException('Report not found.');
        }

        $totalMessages = 0;
        $passMessages = 0;
        foreach ($report->records as $record) {
            $totalMessages += $record->count;
            if ('pass' === $record->dkimResult || 'pass' === $record->spfResult) {
                $passMessages += $record->count;
            }
        }
        $failMessages = $totalMessages - $passMessages;

        $donutConfig = [
            'chart' => ['type' => 'donut', 'height' => 200],
            'series' => [$passMessages, $failMessages],
            'labels' => ['Pass', 'Fail'],
            'colors' => ['#34d399', '#f87171'],
            'legend' => ['position' => 'bottom'],
            'dataLabels' => ['enabled' => true],
        ];

        return $this->render('dashboard/report_detail.html.twig', [
            'report' => $report,
            'totalMessages' => $totalMessages,
            'passMessages' => $passMessages,
            'failMessages' => $failMessages,
            'donutConfig' => $donutConfig,
        ]);
    }
}

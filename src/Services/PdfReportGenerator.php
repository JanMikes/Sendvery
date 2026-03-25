<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final readonly class PdfReportGenerator
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function generate(array $data): string
    {
        $html = $this->twig->render('pdf/domain_report.html.twig', $data);

        $options = new Options();
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}

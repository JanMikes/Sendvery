<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DnsMonitoringController extends AbstractController
{
    #[Route('/tools/dns-monitoring', name: 'tools_dns_monitoring')]
    public function __invoke(): Response
    {
        return $this->render('tools/dns-monitoring.html.twig');
    }
}

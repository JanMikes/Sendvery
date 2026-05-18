<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DomainHealthController extends AbstractController
{
    #[Route('/tools/domain-health', name: 'tools_domain_health', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/domain-health.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
        ]);
    }
}

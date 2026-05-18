<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DmarcCheckerController extends AbstractController
{
    #[Route('/tools/dmarc-checker', name: 'tools_dmarc_checker', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/dmarc-checker.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
        ]);
    }
}

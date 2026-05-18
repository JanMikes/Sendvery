<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DkimCheckerController extends AbstractController
{
    #[Route('/tools/dkim-checker', name: 'tools_dkim_checker', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/dkim-checker.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
            'initialSelector' => trim($request->query->getString('selector')),
        ]);
    }
}

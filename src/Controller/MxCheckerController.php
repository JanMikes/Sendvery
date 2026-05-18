<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MxCheckerController extends AbstractController
{
    #[Route('/tools/mx-checker', name: 'tools_mx_checker', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/mx-checker.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
        ]);
    }
}

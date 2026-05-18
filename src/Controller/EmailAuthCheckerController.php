<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmailAuthCheckerController extends AbstractController
{
    #[Route('/tools/email-auth-checker', name: 'tools_email_auth_checker', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/email-auth-checker.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
        ]);
    }
}

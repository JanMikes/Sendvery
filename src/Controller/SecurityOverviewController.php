<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityOverviewController extends AbstractController
{
    #[Route('/legal/security', name: 'legal_security')]
    public function __invoke(): Response
    {
        return $this->render('legal/security.html.twig');
    }
}

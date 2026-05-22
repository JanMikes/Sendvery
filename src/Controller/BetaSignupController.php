<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BetaSignupController extends AbstractController
{
    #[Route('/beta', name: 'beta_signup', methods: ['GET', 'POST'])]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('home', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}

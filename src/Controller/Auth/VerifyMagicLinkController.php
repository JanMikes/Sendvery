<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VerifyMagicLinkController extends AbstractController
{
    #[Route('/login/verify/{token}', name: 'auth_verify_magic_link', methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        // This route is intercepted by MagicLinkAuthenticator
        // If we reach here, authentication has already been handled
        return $this->redirectToRoute('dashboard_overview');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LoginFailedController extends AbstractController
{
    #[Route('/login/failed', name: 'auth_login_failed', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $error = $request->getSession()->get('auth_error', 'An error occurred during login.');
        $request->getSession()->remove('auth_error');

        return $this->render('auth/login_failed.html.twig', [
            'error' => $error,
        ]);
    }
}

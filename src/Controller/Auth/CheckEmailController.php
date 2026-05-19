<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckEmailController extends AbstractController
{
    #[Route('/login/check-email', name: 'auth_check_email', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $email = $request->getSession()->get('pending_login_email');

        if (!is_string($email) || '' === $email) {
            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/check_email.html.twig', [
            'email' => $email,
        ]);
    }
}

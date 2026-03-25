<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Message\RequestMagicLink;
use App\Services\IdentityProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LoginController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/login', name: 'auth_login', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_overview');
        }

        if ($request->isMethod('POST')) {
            return $this->handleLogin($request);
        }

        return $this->render('auth/login.html.twig');
    }

    private function handleLogin(Request $request): Response
    {
        $email = trim($request->request->getString('email'));

        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(message: 'Please enter your email address.'),
            new Assert\Email(message: 'Please enter a valid email address.'),
        ]);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = (string) $violation->getMessage();
            }

            return $this->render('auth/login.html.twig', [
                'email' => $email,
                'errors' => $errors,
            ]);
        }

        $this->commandBus->dispatch(new RequestMagicLink(
            tokenId: $this->identityProvider->nextIdentity(),
            email: strtolower($email),
        ));

        return $this->render('auth/check_email.html.twig', [
            'email' => $email,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\FormData\BetaSignupData;
use App\Message\RegisterBetaSignup;
use App\Repository\BetaSignupRepository;
use App\Services\IdentityProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BetaSignupController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
        private readonly BetaSignupRepository $betaSignupRepository,
    ) {
    }

    #[Route('/beta', name: 'beta_signup', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new BetaSignupData();
        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            $data->email = trim($request->request->getString('email'));
            $domainCount = $request->request->getString('domain_count');
            $data->domainCount = $domainCount !== '' ? (int) $domainCount : null;
            $painPoint = $request->request->getString('pain_point');
            $data->painPoint = $painPoint !== '' ? $painPoint : null;

            $violations = $this->validator->validate($data);

            if (count($violations) > 0) {
                foreach ($violations as $violation) {
                    $errors[] = (string) $violation->getMessage();
                }
            } else {
                $existing = $this->betaSignupRepository->findByEmail($data->email);

                if ($existing !== null) {
                    $success = true;
                } else {
                    $signupId = $this->identityProvider->nextIdentity();
                    $source = $request->request->getString('source', 'beta-page');

                    $this->commandBus->dispatch(new RegisterBetaSignup(
                        signupId: $signupId,
                        email: $data->email,
                        domainCount: $data->domainCount,
                        painPoint: $data->painPoint,
                        source: $source,
                    ));

                    $success = true;
                }
            }

            if ($request->headers->has('Turbo-Frame')) {
                return $this->render('beta/_form.html.twig', [
                    'data' => $data,
                    'errors' => $errors,
                    'success' => $success,
                ]);
            }
        }

        return $this->render('beta/signup.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'success' => $success,
        ]);
    }
}

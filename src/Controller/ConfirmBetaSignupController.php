<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ConfirmBetaSignupController extends AbstractController
{
    public function __construct(
        private readonly BetaSignupRepository $betaSignupRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/beta/confirm/{token}', name: 'beta_confirm', methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        $signup = $this->betaSignupRepository->findByToken($token);

        if (null === $signup) {
            throw new NotFoundHttpException();
        }

        if (null === $signup->confirmedAt) {
            $signup->confirm($this->clock->now());
            $this->entityManager->flush();
        }

        return $this->render('beta/confirmed.html.twig');
    }
}

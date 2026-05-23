<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrivacyPolicyController extends AbstractController
{
    #[Route('/legal/privacy', name: 'legal_privacy')]
    public function __invoke(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }
}

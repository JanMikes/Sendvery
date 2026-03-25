<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OpenSourceController extends AbstractController
{
    #[Route('/about/open-source', name: 'about_open_source')]
    public function __invoke(): Response
    {
        return $this->render('about/open-source.html.twig');
    }
}

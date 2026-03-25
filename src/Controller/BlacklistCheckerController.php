<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlacklistCheckerController extends AbstractController
{
    #[Route('/tools/blacklist-checker', name: 'tools_blacklist_checker')]
    public function __invoke(): Response
    {
        return $this->render('tools/blacklist-checker.html.twig');
    }
}

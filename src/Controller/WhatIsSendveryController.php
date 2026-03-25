<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WhatIsSendveryController extends AbstractController
{
    #[Route('/about/what-is-sendvery', name: 'about_what_is_sendvery')]
    public function __invoke(): Response
    {
        return $this->render('about/what-is-sendvery.html.twig');
    }
}

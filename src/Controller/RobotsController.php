<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RobotsController extends AbstractController
{
    #[Route('/robots.txt', name: 'robots')]
    public function __invoke(): Response
    {
        $sitemapUrl = $this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $content = <<<TXT
            User-agent: *
            Allow: /

            Sitemap: {$sitemapUrl}
            TXT;

        return new Response($content, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}

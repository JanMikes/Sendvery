<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    /** @var list<array{route: string, priority: string, changefreq: string, params?: array<string, string>}> */
    private const array ROUTES = [
        ['route' => 'home', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['route' => 'tools_spf_checker', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_dkim_checker', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_dmarc_checker', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_email_auth_checker', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_dns_monitoring', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_mx_checker', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_blacklist_checker', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'tools_domain_health', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'beta_signup', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ['route' => 'knowledge_base_index', 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['route' => 'knowledge_base_article', 'priority' => '0.8', 'changefreq' => 'monthly', 'params' => ['slug' => 'what-is-dmarc']],
        ['route' => 'knowledge_base_article', 'priority' => '0.8', 'changefreq' => 'monthly', 'params' => ['slug' => 'spf-record-guide']],
        ['route' => 'knowledge_base_article', 'priority' => '0.8', 'changefreq' => 'monthly', 'params' => ['slug' => 'email-authentication-explained']],
        ['route' => 'pricing', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ['route' => 'about_what_is_sendvery', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['route' => 'about_open_source', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ];

    #[Route('/sitemap.xml', name: 'sitemap', defaults: ['_format' => 'xml'])]
    public function __invoke(): Response
    {
        $urls = [];

        foreach (self::ROUTES as $entry) {
            $urls[] = [
                'loc' => $this->generateUrl($entry['route'], $entry['params'] ?? [], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority' => $entry['priority'],
                'changefreq' => $entry['changefreq'],
            ];
        }

        $response = new Response(
            $this->renderView('seo/sitemap.xml.twig', ['urls' => $urls]),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml'],
        );

        return $response;
    }
}

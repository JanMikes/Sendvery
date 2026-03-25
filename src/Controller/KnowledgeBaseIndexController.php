<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class KnowledgeBaseIndexController extends AbstractController
{
    /** @var list<array{slug: string, title: string, excerpt: string, category: string}> */
    public const array GUIDES = [
        [
            'slug' => 'what-is-dmarc',
            'title' => 'What is DMARC and Why Should You Care?',
            'excerpt' => 'A plain-English guide to DMARC — how it protects against email spoofing, the three policies, and how to set it up step by step.',
            'category' => 'Email Authentication Basics',
        ],
        [
            'slug' => 'spf-record-guide',
            'title' => 'SPF Record: The Complete Guide',
            'excerpt' => 'Everything about SPF records — syntax, mechanisms, the 10 DNS lookup limit, common mistakes, and how to fix them.',
            'category' => 'DNS & Records',
        ],
        [
            'slug' => 'email-authentication-explained',
            'title' => 'Email Authentication: SPF, DKIM, and DMARC Explained',
            'excerpt' => 'How SPF, DKIM, and DMARC work together to protect your domain. Why you need all three and how the authentication flow works.',
            'category' => 'Email Authentication Basics',
        ],
    ];

    #[Route('/learn', name: 'knowledge_base_index')]
    public function __invoke(): Response
    {
        $categories = [];

        foreach (self::GUIDES as $guide) {
            $categories[$guide['category']][] = $guide;
        }

        return $this->render('knowledge_base/index.html.twig', [
            'categories' => $categories,
            'guides' => self::GUIDES,
        ]);
    }
}

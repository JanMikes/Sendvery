<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class KnowledgeBaseIndexController extends AbstractController
{
    /**
     * TASK-148: each guide ships its own `publishedAt` + `updatedAt` ISO 8601
     * dates so Google sees per-article freshness signals. Previously the
     * Article JSON-LD layout hardcoded `2026-03-25` for every article, which
     * meant Google treated them as one freshness cohort and the article
     * actually updated later lost rank against the original. Update
     * `updatedAt` on substantial content edits to refresh the signal.
     *
     * @var list<array{slug: string, title: string, excerpt: string, category: string, publishedAt: string, updatedAt: string}>
     */
    public const array GUIDES = [
        [
            'slug' => 'what-is-dmarc',
            'title' => 'What is DMARC and Why Should You Care?',
            'excerpt' => 'A plain-English guide to DMARC — how it protects against email spoofing, the three policies, and how to set it up step by step.',
            'category' => 'Email Authentication Basics',
            'publishedAt' => '2026-03-25',
            'updatedAt' => '2026-03-25',
        ],
        [
            'slug' => 'what-is-dkim',
            'title' => 'What is DKIM and How Does It Work?',
            'excerpt' => 'How DKIM signs your outgoing mail with a private key, what receivers do with the public key in DNS, selector rotation, key sizes, and the link to DMARC alignment.',
            'category' => 'Email Authentication Basics',
            'publishedAt' => '2026-04-02',
            'updatedAt' => '2026-04-02',
        ],
        [
            'slug' => 'email-authentication-explained',
            'title' => 'Email Authentication: SPF, DKIM, and DMARC Explained',
            'excerpt' => 'How SPF, DKIM, and DMARC work together to protect your domain. Why you need all three and how the authentication flow works.',
            'category' => 'Email Authentication Basics',
            'publishedAt' => '2026-03-28',
            'updatedAt' => '2026-03-28',
        ],
        [
            'slug' => 'dmarc-migration-guide-none-to-reject',
            'title' => 'How to Move from p=none to p=reject: A Step-by-Step DMARC Migration Guide',
            'excerpt' => 'A safe, phased migration plan — monitor, ramp quarantine, ramp reject, tighten alignment — with explicit checkpoints, rollback rules, and report triage tips.',
            'category' => 'Email Authentication Basics',
            'publishedAt' => '2026-04-15',
            'updatedAt' => '2026-04-15',
        ],
        [
            'slug' => 'gmail-yahoo-bulk-sender-requirements-2024',
            'title' => 'Gmail & Yahoo Bulk Sender Requirements (2024+): What You Need to Comply',
            'excerpt' => 'A practical guide to the February 2024 requirements: SPF + DKIM, DMARC with alignment, one-click List-Unsubscribe, the 0.3% complaint threshold, and how to audit your domain.',
            'category' => 'Email Authentication Basics',
            'publishedAt' => '2026-04-08',
            'updatedAt' => '2026-04-08',
        ],
        [
            'slug' => 'spf-record-guide',
            'title' => 'SPF Record: The Complete Guide',
            'excerpt' => 'Everything about SPF records — syntax, mechanisms, the 10 DNS lookup limit, common mistakes, and how to fix them.',
            'category' => 'DNS & Records',
            'publishedAt' => '2026-04-05',
            'updatedAt' => '2026-04-05',
        ],
        [
            'slug' => 'mx-records-explained',
            'title' => 'MX Records Explained: How Email Routing Works',
            'excerpt' => 'How email routing works under the hood: priorities, multi-MX failover, common provider records, and the subtle interaction between MX, SPF, and reverse DNS.',
            'category' => 'DNS & Records',
            'publishedAt' => '2026-04-12',
            'updatedAt' => '2026-04-12',
        ],
        [
            'slug' => 'authorizing-senders-explained',
            'title' => 'Authorizing Senders Explained',
            'excerpt' => 'When a sender appears in your inventory you can authorize, revoke, or keep watching. Explains how Sendvery picks the recommendation and what each label actually changes.',
            'category' => 'Email Authentication Basics',
            'publishedAt' => '2026-04-20',
            'updatedAt' => '2026-04-20',
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

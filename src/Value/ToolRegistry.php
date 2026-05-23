<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Mirror of the per-tool controllers under `src/Controller/*Checker*` —
 * gives the OG-image resolver a single place to look up a tool slug's
 * title and category badge. Mirrors the
 * `KnowledgeBaseIndexController::GUIDES` shape so both resolvers stay
 * parallel.
 */
final readonly class ToolRegistry
{
    /** @var list<array{slug: string, title: string, category: string}> */
    public const array TOOLS = [
        [
            'slug' => 'dmarc-checker',
            'title' => 'DMARC Record Checker',
            'category' => 'Email Authentication',
        ],
        [
            'slug' => 'spf-checker',
            'title' => 'SPF Record Checker',
            'category' => 'Email Authentication',
        ],
        [
            'slug' => 'dkim-checker',
            'title' => 'DKIM Record Checker',
            'category' => 'Email Authentication',
        ],
        [
            'slug' => 'mx-checker',
            'title' => 'MX Record Checker',
            'category' => 'DNS Tools',
        ],
        [
            'slug' => 'email-auth-checker',
            'title' => 'Email Authentication Checker',
            'category' => 'Email Authentication',
        ],
        [
            'slug' => 'domain-health',
            'title' => 'Domain Health Check',
            'category' => 'Email Health',
        ],
        [
            'slug' => 'blacklist-checker',
            'title' => 'Blacklist Checker',
            'category' => 'Deliverability',
        ],
        [
            'slug' => 'dns-monitoring',
            'title' => 'DNS Monitoring',
            'category' => 'DNS Tools',
        ],
    ];
}

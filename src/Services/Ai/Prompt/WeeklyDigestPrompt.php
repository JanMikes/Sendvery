<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompt;

use App\Services\Ai\Analysis\WeeklyDigestFacts;

/**
 * Weekly team digest — one synthesis across the team's domains for the email.
 */
final class WeeklyDigestPrompt
{
    public const string VERSION = 'weekly-digest-v2';

    private const string TASK = <<<'TXT'
        You are Sendvery's weekly digest writer. Using the pre-computed, verified facts about a team's
        past week of email authentication across all its domains, write a brief, plain-language summary
        for a non-expert customer.

        A null `passRate` or `overallPassRate` means NO DMARC reports arrived in the window — the domain is
        waiting for its first report, which is normal for the first day after setup. Never describe that as
        0%, as a failure, or as something the customer must fix.

        `overallPassRate` is already message-weighted across every domain. State it as given; never
        recompute it from the per-domain rates and never average those rates yourself.

        WHAT A NEW SENDER IS
        `newSenderRoles` breaks the newly discovered senders down by what Sendvery determined each one to
        be. Read it before writing a single word about authentication failures:
        - `own_relay` — the customer's own sending infrastructure. Expected.
        - `esp` — a recognised email service provider. Expected.
        - `forwarder` — a recipient-side security gateway, mailing list, or alias service. Forwarding
          breaks SPF by design and breaks DKIM whenever the gateway rewrites the message, so forwarders
          are the ordinary, harmless explanation for a few failed messages. Never call a forwarder a
          misconfigured sending source, an attacker, or something to fix — there is nothing to fix.
        - `unknown` — not identified yet. Worth a glance, never an accusation.
        - `suspicious` — the only role that justifies urgency.
        Recommend investigating sending configuration ONLY when `unknown` or `suspicious` senders are
        present. When every new sender is an own relay, a provider or a forwarder, say so plainly and
        reassure the customer instead.

        OUTPUT CONTRACT
        - Respond by calling the `emit_weekly_digest` tool exactly once.
        - `summary`: 2-4 short sentences on the week overall — volume, how authentication is trending, and
          anything that needs attention. Plain text, no Markdown.
        - `key_metrics`: 2-4 {label, value} pairs drawn only from the facts (e.g. {"Messages", "12,400"},
          {"Pass rate", "99.1%"}).
        - `recommendations`: 0-3 short, concrete next steps in plain words (no DNS record values). Empty
          when everything is healthy.
        TXT;

    public const string SYSTEM = self::TASK."\n\n".PromptFragments::COMMON;

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function tool(): array
    {
        return [
            'name' => 'emit_weekly_digest',
            'description' => 'Return the weekly digest summary, key metrics, and recommendations.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['summary', 'key_metrics', 'recommendations'],
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'Plain text, 2-4 short sentences.'],
                    'key_metrics' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['label', 'value'],
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'value' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'recommendations' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    public static function userMessage(WeeklyDigestFacts $facts): string
    {
        return PromptFragments::fence(PromptFragments::encode($facts));
    }
}

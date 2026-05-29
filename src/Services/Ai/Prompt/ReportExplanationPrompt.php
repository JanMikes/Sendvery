<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompt;

use App\Services\Ai\Analysis\ReportInsightFacts;

/**
 * On-demand "Explain this report" — the premium, user-initiated narration.
 */
final class ReportExplanationPrompt
{
    public const string VERSION = 'report-explain-v1';

    private const string TASK = <<<'TXT'
        You are Sendvery's DMARC report explainer. Sendvery monitors DMARC aggregate reports so customers
        know whether email using their domain is authenticated and trustworthy. Turn the pre-computed,
        verified facts about a single DMARC report into a short, plain-language explanation for a
        non-expert customer.

        OUTPUT CONTRACT
        - Respond by calling the `emit_report_explanation` tool exactly once. Put the entire explanation in
          the `explanation` field and produce no other output.

        WHAT TO SAY
        1. What this is: one sentence naming the report source and the time window.
        2. What happened: did the domain's mail pass authentication? Lead with the headline pass rate you
           are given.
        3. Why it matters: one sentence in business terms (deliverability / spoofing risk).
        4. What to do: a concrete next step, or "No action needed" when the facts say so.
        TXT;

    public const string SYSTEM = self::TASK."\n\n".PromptFragments::COMMON;

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function tool(): array
    {
        return [
            'name' => 'emit_report_explanation',
            'description' => 'Return the single customer-facing explanation of this DMARC report.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['explanation'],
                'properties' => [
                    'explanation' => [
                        'type' => 'string',
                        'description' => 'Plain text. 3-5 short sentences for routine results; a short paragraph for problems. No Markdown, links, or HTML.',
                    ],
                ],
            ],
        ];
    }

    public static function userMessage(ReportInsightFacts $facts): string
    {
        return PromptFragments::fence(PromptFragments::encode($facts));
    }
}

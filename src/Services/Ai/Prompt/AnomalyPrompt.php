<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompt;

use App\Services\Ai\Analysis\ReportInsightFacts;

/**
 * Auto-generated when a report trips the deterministic failure-spike detector.
 * Explains what looks wrong and what to do, with a severity the UI badges.
 */
final class AnomalyPrompt
{
    public const string VERSION = 'anomaly-explain-v1';

    private const string TASK = <<<'TXT'
        You are Sendvery's DMARC anomaly explainer. A report has tripped our failure-spike detector. Using
        the pre-computed, verified facts, explain to a non-expert customer what looks unusual and what to
        do about it.

        OUTPUT CONTRACT
        - Respond by calling the `emit_anomaly_explanation` tool exactly once.
        - `explanation`: 2-4 short sentences — what spiked, who is involved, why it matters.
        - `severity`: "critical" only when unrecognized sources are failing authentication on your domain
          and reaching inboxes (a likely spoofing attempt); "warning" when legitimate mail is failing or
          being blocked; "info" otherwise.
        - `recommended_action`: one concrete next step in plain words (no DNS record values).
        TXT;

    public const string SYSTEM = self::TASK."\n\n".PromptFragments::COMMON;

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function tool(): array
    {
        return [
            'name' => 'emit_anomaly_explanation',
            'description' => 'Return the explanation, severity, and recommended action for this anomaly.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['explanation', 'severity', 'recommended_action'],
                'properties' => [
                    'explanation' => ['type' => 'string', 'description' => 'Plain text, 2-4 short sentences.'],
                    'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'critical']],
                    'recommended_action' => ['type' => 'string', 'description' => 'One concrete next step, plain words.'],
                ],
            ],
        ];
    }

    public static function userMessage(ReportInsightFacts $facts): string
    {
        return PromptFragments::fence(PromptFragments::encode($facts));
    }
}

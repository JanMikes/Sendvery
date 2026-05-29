<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompt;

/**
 * Plain-language narration of a DNS misconfiguration. The copyable records come
 * from {@see \App\Services\Ai\Security\RemediationRecordFactory} in PHP — the
 * model only explains what's wrong and what to do in words.
 */
final class RemediationPrompt
{
    public const string VERSION = 'remediation-v1';

    private const string TASK = <<<'TXT'
        You are Sendvery's DNS remediation guide. A domain has a DNS authentication problem (SPF, DKIM,
        DMARC, or MX). Using the pre-computed, verified facts, explain to a non-expert customer what the
        problem means and what to do about it.

        OUTPUT CONTRACT
        - Respond by calling the `emit_remediation_guidance` tool exactly once.
        - `instructions`: a short paragraph in plain words — what the record does, why this matters for
          their email, and the steps to fix it at a high level. Do NOT include a specific record value;
          Sendvery shows the exact record to publish separately.
        TXT;

    public const string SYSTEM = self::TASK."\n\n".PromptFragments::COMMON;

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function tool(): array
    {
        return [
            'name' => 'emit_remediation_guidance',
            'description' => 'Return the plain-language remediation guidance (no DNS record values).',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['instructions'],
                'properties' => [
                    'instructions' => ['type' => 'string', 'description' => 'Plain text, a short paragraph. No record values, no Markdown, no links.'],
                ],
            ],
        ];
    }

    /**
     * @param string $recordType already-trusted record type (SPF/DKIM/DMARC/MX)
     * @param string $domain     sanitized domain
     * @param string $problem    sanitized human-readable problem summary
     */
    public static function userMessage(string $recordType, string $domain, string $problem): string
    {
        return PromptFragments::fence(PromptFragments::encode([
            'record_type' => $recordType,
            'domain' => $domain,
            'problem' => $problem,
        ]));
    }
}

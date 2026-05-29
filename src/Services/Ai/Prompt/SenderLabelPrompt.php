<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompt;

/**
 * Names an email-sending service from its IP and reverse-DNS hostname. Only
 * reached for sources the deterministic {@see \App\Services\OrganizationMapper}
 * could not already identify — Haiku, lowest tier.
 */
final class SenderLabelPrompt
{
    public const string VERSION = 'sender-label-v1';

    private const string TASK = <<<'TXT'
        You are Sendvery's sender identifier. Given a sending IP address, the domain it sent mail for, and
        (when available) its reverse-DNS hostname, name the email service or organization most likely
        operating it.

        OUTPUT CONTRACT
        - Respond by calling the `emit_sender_label` tool exactly once.
        - `label`: a short, human-friendly name (e.g. "Google Workspace", "SendGrid", "Amazon SES"). Use
          "Unknown sender" if the data does not clearly identify one.
        - `confidence`: 0 to 1 — how sure you are. Use a low value for "Unknown sender".
        TXT;

    public const string SYSTEM = self::TASK."\n\n".PromptFragments::COMMON;

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function tool(): array
    {
        return [
            'name' => 'emit_sender_label',
            'description' => 'Return a short label and confidence for the sending source.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['label', 'confidence'],
                'properties' => [
                    'label' => ['type' => 'string', 'description' => 'Short, human-friendly sender name.'],
                    'confidence' => ['type' => 'number', 'description' => 'Confidence from 0 to 1.'],
                ],
            ],
        ];
    }

    public static function userMessage(string $ip, string $domain, ?string $hostname): string
    {
        return PromptFragments::fence(PromptFragments::encode([
            'ip' => $ip,
            'domain' => $domain,
            'reverse_dns_hostname' => $hostname,
        ]));
    }
}

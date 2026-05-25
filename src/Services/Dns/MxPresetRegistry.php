<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Canonical MX record sets for the major mailbox providers, used by the
 * public MX-generator UI. The Microsoft preset uses a `your-tenant`
 * placeholder host that the Stimulus controller rewrites client-side with
 * the user-supplied tenant slug.
 */
final readonly class MxPresetRegistry
{
    /**
     * @var list<array{key: string, label: string, records: list<array{priority: int, host: string}>}>
     */
    private const array PRESETS = [
        [
            'key' => 'google',
            'label' => 'Google Workspace',
            'records' => [
                ['priority' => 1,  'host' => 'ASPMX.L.GOOGLE.COM'],
                ['priority' => 5,  'host' => 'ALT1.ASPMX.L.GOOGLE.COM'],
                ['priority' => 5,  'host' => 'ALT2.ASPMX.L.GOOGLE.COM'],
                ['priority' => 10, 'host' => 'ALT3.ASPMX.L.GOOGLE.COM'],
                ['priority' => 10, 'host' => 'ALT4.ASPMX.L.GOOGLE.COM'],
            ],
        ],
        [
            'key' => 'microsoft',
            'label' => 'Microsoft 365',
            'records' => [
                ['priority' => 0, 'host' => 'your-tenant.mail.protection.outlook.com'],
            ],
        ],
        [
            'key' => 'protonmail',
            'label' => 'Proton Mail',
            'records' => [
                ['priority' => 10, 'host' => 'mail.protonmail.ch'],
                ['priority' => 20, 'host' => 'mailsec.protonmail.ch'],
            ],
        ],
        [
            'key' => 'fastmail',
            'label' => 'Fastmail',
            'records' => [
                ['priority' => 10, 'host' => 'in1-smtp.messagingengine.com'],
                ['priority' => 20, 'host' => 'in2-smtp.messagingengine.com'],
            ],
        ],
        [
            'key' => 'zoho',
            'label' => 'Zoho Mail',
            'records' => [
                ['priority' => 10, 'host' => 'mx.zoho.com'],
                ['priority' => 20, 'host' => 'mx2.zoho.com'],
                ['priority' => 50, 'host' => 'mx3.zoho.com'],
            ],
        ],
    ];

    /**
     * @return list<array{key: string, label: string, records: list<array{priority: int, host: string}>}>
     */
    public function all(): array
    {
        return self::PRESETS;
    }

    /**
     * JSON payload for the Stimulus controller's
     * `data-mx-generator-presets-value` attribute.
     */
    public function allAsJson(): string
    {
        return json_encode(self::PRESETS, JSON_THROW_ON_ERROR);
    }
}

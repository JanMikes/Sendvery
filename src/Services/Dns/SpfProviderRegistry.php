<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Canonical include: targets for the most common transactional / marketing
 * senders, exposed to the public SPF-generator UI. Keep it short and curated
 * — every entry is a real `include:` lookup against the 10-DNS-lookup limit,
 * so we deliberately ship only the providers that pay rent in our reports.
 */
final readonly class SpfProviderRegistry
{
    /**
     * @var list<array{key: string, label: string, include: string}>
     */
    private const array PROVIDERS = [
        ['key' => 'google',     'label' => 'Google Workspace', 'include' => '_spf.google.com'],
        ['key' => 'microsoft',  'label' => 'Microsoft 365',    'include' => 'spf.protection.outlook.com'],
        ['key' => 'mailchimp',  'label' => 'Mailchimp',        'include' => 'servers.mcsv.net'],
        ['key' => 'postmark',   'label' => 'Postmark',         'include' => 'spf.mtasv.net'],
        ['key' => 'sendgrid',   'label' => 'SendGrid',         'include' => 'sendgrid.net'],
        ['key' => 'mailgun',    'label' => 'Mailgun',          'include' => 'mailgun.org'],
        ['key' => 'amazonses',  'label' => 'Amazon SES',       'include' => 'amazonses.com'],
        ['key' => 'brevo',      'label' => 'Brevo',            'include' => 'spf.brevo.com'],
        ['key' => 'resend',     'label' => 'Resend',           'include' => 'spf.resend.com'],
        ['key' => 'loops',      'label' => 'Loops',            'include' => 'spf.loops.so'],
    ];

    /**
     * @return list<array{key: string, label: string, include: string}>
     */
    public function all(): array
    {
        return self::PROVIDERS;
    }

    /**
     * JSON payload meant for the Stimulus controller's
     * `data-spf-generator-providers-value` attribute. Always emits a valid
     * array literal — non-encodable input would be a programmer error here.
     */
    public function allAsJson(): string
    {
        return json_encode(self::PROVIDERS, JSON_THROW_ON_ERROR);
    }
}

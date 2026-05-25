<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\SpfProvider;

/**
 * Canonical include: targets for the most common transactional / marketing
 * senders, exposed to the public SPF-generator UI. Keep it short and curated
 * — every entry is a real `include:` lookup against the 10-DNS-lookup limit,
 * so we deliberately ship only the providers that pay rent in our reports.
 *
 * TASK-155: entries are SpfProvider value objects rather than shape arrays
 * (per CLAUDE.md "objects over arrays"). `allAsJson()` emits byte-identical
 * JSON to the round-8 array shape via JsonSerializable property order.
 */
final readonly class SpfProviderRegistry
{
    /**
     * @return list<SpfProvider>
     */
    public function all(): array
    {
        return [
            new SpfProvider('google',    'Google Workspace', '_spf.google.com'),
            new SpfProvider('microsoft', 'Microsoft 365',    'spf.protection.outlook.com'),
            new SpfProvider('mailchimp', 'Mailchimp',        'servers.mcsv.net'),
            new SpfProvider('postmark',  'Postmark',         'spf.mtasv.net'),
            new SpfProvider('sendgrid',  'SendGrid',         'sendgrid.net'),
            new SpfProvider('mailgun',   'Mailgun',          'mailgun.org'),
            new SpfProvider('amazonses', 'Amazon SES',       'amazonses.com'),
            new SpfProvider('brevo',     'Brevo',            'spf.brevo.com'),
            new SpfProvider('resend',    'Resend',           'spf.resend.com'),
            new SpfProvider('loops',     'Loops',            'spf.loops.so'),
        ];
    }

    /**
     * JSON payload meant for the Stimulus controller's
     * `data-spf-generator-providers-value` attribute. Always emits a valid
     * array literal — non-encodable input would be a programmer error here.
     */
    public function allAsJson(): string
    {
        return json_encode($this->all(), JSON_THROW_ON_ERROR);
    }
}

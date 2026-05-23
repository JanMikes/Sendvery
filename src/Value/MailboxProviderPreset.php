<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Server presets for the mailbox-setup wizard. Picked over a backing
 * enum because the cases carry several typed properties (host, port,
 * encryption, app-password hint) that enums can't expose cleanly. The
 * Stimulus `mailbox-preset` controller consumes {@see presetsJson()} to
 * auto-fill the host/port/encryption inputs and toggle the app-password
 * banner when the user picks Gmail or Microsoft 365.
 */
final readonly class MailboxProviderPreset
{
    public function __construct(
        public string $key,
        public string $label,
        public string $host,
        public int $port,
        public MailboxEncryption $encryption,
        public bool $requiresAppPassword,
    ) {
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            new self(
                key: 'gmail',
                label: 'Gmail',
                host: 'imap.gmail.com',
                port: 993,
                encryption: MailboxEncryption::Ssl,
                requiresAppPassword: true,
            ),
            new self(
                key: 'outlook',
                label: 'Outlook / Microsoft 365',
                host: 'outlook.office365.com',
                port: 993,
                encryption: MailboxEncryption::Ssl,
                requiresAppPassword: true,
            ),
            new self(
                key: 'fastmail',
                label: 'Fastmail',
                host: 'imap.fastmail.com',
                port: 993,
                encryption: MailboxEncryption::Ssl,
                requiresAppPassword: false,
            ),
            new self(
                key: 'yahoo',
                label: 'Yahoo Mail',
                host: 'imap.mail.yahoo.com',
                port: 993,
                encryption: MailboxEncryption::Ssl,
                requiresAppPassword: false,
            ),
            new self(
                key: 'seznam',
                label: 'Seznam',
                host: 'imap.seznam.cz',
                port: 993,
                encryption: MailboxEncryption::Ssl,
                requiresAppPassword: false,
            ),
            new self(
                key: 'custom',
                label: 'Custom',
                host: '',
                port: 993,
                encryption: MailboxEncryption::Ssl,
                requiresAppPassword: false,
            ),
        ];
    }

    public static function find(string $key): ?self
    {
        foreach (self::cases() as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * JSON the Stimulus controller reads via a `data-*-value` attribute.
     * Keyed by preset key for O(1) lookup in the change handler.
     */
    public static function presetsJson(): string
    {
        $map = [];
        foreach (self::cases() as $preset) {
            $map[$preset->key] = [
                'host' => $preset->host,
                'port' => $preset->port,
                'encryption' => $preset->encryption->value,
                'requiresAppPassword' => $preset->requiresAppPassword,
            ];
        }

        return json_encode($map, \JSON_THROW_ON_ERROR);
    }
}

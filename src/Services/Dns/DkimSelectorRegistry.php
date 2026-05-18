<?php

declare(strict_types=1);

namespace App\Services\Dns;

final readonly class DkimSelectorRegistry
{
    /**
     * Provider name (as returned by OrganizationMapper) → list of DKIM selectors that provider publishes.
     *
     * Some selectors are account-specific and unguessable (Amazon SES uses random tokens,
     * HubSpot uses account-IDs, Seznam uses date-based names). For those providers we still
     * map known generic selectors where applicable.
     *
     * @var array<string, list<string>>
     */
    private const array PROVIDER_SELECTORS = [
        'Google' => ['google'],
        'Microsoft' => ['selector1', 'selector2'],
        'Mailgun' => ['k1', 'mta', 'pic', 'mailgun'],
        'SendGrid' => ['s1', 's2', 'sendgrid'],
        'Mailchimp' => ['k1', 'k2', 'k3', 'mte1', 'mte2'],
        'Postmark' => ['pm', 'pm-bounces'],
        'Amazon SES' => ['amazonses'],
        'SparkPost' => ['scph0317', 'sparkpost'],
        'Mailjet' => ['mailjet'],
        'Brevo' => ['mail', 'brevo1', 'brevo2', 'sib'],
        'Constant Contact' => ['k1', 'k2'],
        'HubSpot' => ['hs1', 'hs2'],
        'Salesforce' => ['mc1', 'mc2'],
        'Salesforce Marketing Cloud' => ['mc1', 'mc2'],
        'Zendesk' => ['zendesk1', 'zendesk2'],
        'Freshdesk' => ['freshdesk1', 'freshdesk2', 'freshdesk'],
        'Intercom' => ['intercom', 'intercom1', 'intercom2'],
        'ActiveCampaign' => ['dk', 'activecampaign'],
        'Campaign Monitor' => ['cm', 'cm1', 'cm2'],
        'GetResponse' => ['getresponse', 'gr'],
        'ConvertKit' => ['ckkey', 's1'],
        'AWeber' => ['aweber'],
        'Drip' => ['drip'],
        'Klaviyo' => ['klaviyo1', 'klaviyo2'],
        'Zoho' => ['zoho', 'zmail'],
        'Yahoo' => ['s1024', 's2048', 'yahoo'],
        'Yandex' => ['mail'],
        'Apple' => ['sig1'],
        'Proton Mail' => ['protonmail', 'protonmail2', 'protonmail3'],
        'Seznam' => ['szn-2022', 'szn20221014', 'szn20231014', 'szn20241014', 'szn20251014'],
        'Elastic Email' => ['api'],
        'SendPulse' => ['sendpulse'],
        'MessageBird' => ['messagebird'],
        'SocketLabs' => ['socketlabs'],
    ];

    /**
     * Generic selectors to try when no provider is detected, or as a fallback after
     * provider-specific selectors fail. Ordered roughly by global usage.
     *
     * @var list<string>
     */
    private const array GENERIC_FALLBACK = [
        'default', 'selector1', 'selector2', 'google', 'k1', 's1', 'dkim', 'mail', 'smtp',
    ];

    /**
     * @param list<string> $providers provider names from OrganizationMapper
     *
     * @return list<string> ordered selectors to probe (provider-specific first, then generic)
     */
    public function selectorsFor(array $providers): array
    {
        $ordered = [];

        foreach ($providers as $provider) {
            foreach (self::PROVIDER_SELECTORS[$provider] ?? [] as $selector) {
                $ordered[$selector] = true;
            }
        }

        foreach (self::GENERIC_FALLBACK as $selector) {
            $ordered[$selector] = true;
        }

        return array_keys($ordered);
    }

    /**
     * Reverse lookup: which provider(s) typically publish this selector?
     *
     * @return list<string>
     */
    public function providersForSelector(string $selector): array
    {
        $normalized = strtolower($selector);
        $matches = [];

        foreach (self::PROVIDER_SELECTORS as $provider => $selectors) {
            foreach ($selectors as $candidate) {
                if (strtolower($candidate) === $normalized) {
                    $matches[] = $provider;

                    break;
                }
            }
        }

        return $matches;
    }
}

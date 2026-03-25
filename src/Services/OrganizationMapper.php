<?php

declare(strict_types=1);

namespace App\Services;

final readonly class OrganizationMapper
{
    /** @var array<string, string> */
    private const array PATTERNS = [
        'google.com' => 'Google',
        'googlemail.com' => 'Google',
        'gmail.com' => 'Google',
        'outlook.com' => 'Microsoft',
        'microsoft.com' => 'Microsoft',
        'hotmail.com' => 'Microsoft',
        'live.com' => 'Microsoft',
        'protection.outlook.com' => 'Microsoft',
        'amazonses.com' => 'Amazon SES',
        'amazonaws.com' => 'Amazon AWS',
        'sendgrid.net' => 'SendGrid',
        'mailchimp.com' => 'Mailchimp',
        'mandrillapp.com' => 'Mailchimp',
        'mailgun.org' => 'Mailgun',
        'mailgun.net' => 'Mailgun',
        'postmarkapp.com' => 'Postmark',
        'sparkpostmail.com' => 'SparkPost',
        'mailjet.com' => 'Mailjet',
        'sendinblue.com' => 'Brevo',
        'brevo.com' => 'Brevo',
        'constantcontact.com' => 'Constant Contact',
        'hubspot.com' => 'HubSpot',
        'hubspotemail.net' => 'HubSpot',
        'salesforce.com' => 'Salesforce',
        'exacttarget.com' => 'Salesforce Marketing Cloud',
        'mcsv.net' => 'Mailchimp',
        'rsgsv.net' => 'Mailchimp',
        'zendesk.com' => 'Zendesk',
        'freshdesk.com' => 'Freshdesk',
        'intercom.io' => 'Intercom',
        'sendpulse.com' => 'SendPulse',
        'elastic.email' => 'Elastic Email',
        'ovh.net' => 'OVH',
        'hetzner.com' => 'Hetzner',
        'cloudflare.com' => 'Cloudflare',
        'seznam.cz' => 'Seznam',
        'protonmail.ch' => 'Proton Mail',
        'proton.me' => 'Proton Mail',
        'zoho.com' => 'Zoho',
        'yahoo.com' => 'Yahoo',
        'yandex.ru' => 'Yandex',
        'yandex.net' => 'Yandex',
        'apple.com' => 'Apple',
        'icloud.com' => 'Apple',
        'shopify.com' => 'Shopify',
        'activecampaign.com' => 'ActiveCampaign',
        'campaignmonitor.com' => 'Campaign Monitor',
        'cmail1.com' => 'Campaign Monitor',
        'cmail2.com' => 'Campaign Monitor',
        'getresponse.com' => 'GetResponse',
        'convertkit.com' => 'ConvertKit',
        'aweber.com' => 'AWeber',
        'drip.com' => 'Drip',
        'klaviyo.com' => 'Klaviyo',
        'simplycast.com' => 'SimplyCast',
        'socketlabs.com' => 'SocketLabs',
        'messagebird.com' => 'MessageBird',
        'twilio.com' => 'Twilio',
        'sendlane.com' => 'Sendlane',
    ];

    public function resolve(string $hostname): ?string
    {
        $hostname = strtolower(trim($hostname, '.'));

        foreach (self::PATTERNS as $pattern => $organization) {
            if ($hostname === $pattern || str_ends_with($hostname, '.'.$pattern)) {
                return $organization;
            }
        }

        return null;
    }
}

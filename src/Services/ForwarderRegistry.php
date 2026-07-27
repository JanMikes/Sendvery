<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Recognises PTR hostnames belonging to recipient-side mail gateways, security
 * appliances, mailing lists and alias/forwarding services (DEC-059 §3.3 rule 2).
 *
 * Why this exists as a distinct check rather than relying on the auth-result
 * heuristic: a forwarder that only re-injects a message breaks SPF and keeps
 * DKIM (the "clean forward" signature, DKIM >= 80% and SPF <= 30%), but a
 * forwarder that rewrites the body — link protection, banner injection —
 * breaks *both*. On results alone that is indistinguishable from spoofing,
 * which is exactly how `ca.cloud-sec-av.com` ended up flagged as a threat
 * (DEC-059 D12). Matching the hostname settles it before the auth results get
 * a vote.
 *
 * Matching follows OrganizationMapper: exact match, or a `.`-boundary suffix
 * match so `eu.cloud-sec-av.com` matches `cloud-sec-av.com` but
 * `evil-cloud-sec-av.com` does not.
 */
final readonly class ForwarderRegistry
{
    /**
     * Domains operated by forwarding infrastructure.
     *
     * @var list<string>
     */
    private const array FORWARDER_DOMAINS = [
        // Recipient-side security gateways / hosted email security
        'protection.outlook.com',
        'inkyphishfence.com',
        'cloud-sec-av.com',
        'mimecast.com',
        'mimecast.co.za',
        'mimecast-offshore.com',
        'pphosted.com',
        'ppe-hosted.com',
        'proofpoint.com',
        'barracudanetworks.com',
        'barracuda.com',
        'cudasvc.com',
        'messagelabs.com',
        'iphmx.com',
        'antispamcloud.com',
        'spamexperts.com',
        'mailanyone.net',
        'mailcontrol.com',
        'mailroute.net',
        'securence.com',
        'emailfiltering.com',
        'libraesva.com',
        'hornetsecurity.com',
        'antispameurope.com',
        'retarus.com',
        'mailprotector.com',
        'trendmicro.com',
        'trendmicro.eu',
        'fireeyecloud.com',
        // Alias / forwarding services: mail arrives, is re-injected under the
        // service's own IPs, and the original SPF no longer covers it.
        'forwardemail.net',
        'improvmx.com',
        'simplelogin.io',
        'anonaddy.me',
        'addy.io',
        'duck.com',
        'pobox.com',
        '33mail.com',
    ];

    /**
     * Hostname labels that mark mailing-list software. A list server explodes an
     * incoming message to its subscribers, which is forwarding by another name
     * and produces the same SPF break.
     *
     * @var list<string>
     */
    private const array MAILING_LIST_LABELS = [
        'lists',
        'listserv',
        'mailman',
        'sympa',
        'majordomo',
        'groups',
    ];

    public function isForwarder(string $hostname): bool
    {
        $normalized = trim(strtolower(trim($hostname)), '.');

        if ('' === $normalized) {
            return false;
        }

        foreach (self::FORWARDER_DOMAINS as $domain) {
            if ($normalized === $domain || str_ends_with($normalized, '.'.$domain)) {
                return true;
            }
        }

        foreach (explode('.', $normalized) as $label) {
            if (in_array($label, self::MAILING_LIST_LABELS, true)) {
                return true;
            }
        }

        return false;
    }
}

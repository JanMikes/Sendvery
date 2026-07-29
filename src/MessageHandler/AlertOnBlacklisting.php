<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\KnownSender;
use App\Events\BlacklistCheckCompleted;
use App\Repository\KnownSenderRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\BlocklistRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turn a confirmed blocklist listing into an alert a human can act on.
 *
 * The previous alert said only "Sending IP <address> is listed on:
 * zen.spamhaus.org, cbl.abuseat.org. This may affect email deliverability.
 * Review the blacklist status and take action to delist." — a Critical badge
 * attached to a bare IP address, with no statement of what the address is, why
 * Sendvery was looking at it, or what the reader is supposed to do. The
 * reported reaction was panic, which is the correct reaction to an alarm that
 * withholds its own context.
 *
 * Three things are added here, all of which change what the reader does next:
 *
 *  1. WHAT THE ADDRESS IS. The IPs checked come from `known_sender` — hosts
 *     observed sending mail for the domain in its own DMARC reports. It is not
 *     the domain's MX (that is inbound), and it is very often the shared
 *     outbound relay of an email provider, which the customer cannot delist and
 *     should not try to.
 *  2. WHETHER DELIVERY IS ACTUALLY AT RISK. Spamhaus and Barracuda are queried
 *     by mailbox providers during SMTP; SORBS and UCEPROTECT largely are not.
 *     Firing the same Critical for both is how an alert channel loses its
 *     meaning. Severity now follows {@see BlocklistRegistry::blocksDelivery()}.
 *  3. WHAT TO DO. Named list, delisting URL, and advice that differs for a
 *     relay you operate versus one your provider does.
 */
#[AsMessageHandler]
final readonly class AlertOnBlacklisting
{
    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private KnownSenderRepository $knownSenderRepository,
        private BlocklistRegistry $blocklists,
    ) {
    }

    public function __invoke(BlacklistCheckCompleted $event): void
    {
        // Only a confirmed listing reaches here. A blocklist that refused the
        // query leaves `isListed` false by construction — see BlacklistChecker.
        if (!$event->isListed || [] === $event->listedOn) {
            return;
        }

        $domain = $this->monitoredDomainRepository->get($event->domainId);
        $sender = $this->knownSenderRepository->findByDomainAndIp($event->domainId, $event->ipAddress);

        $blocksDelivery = $this->blocklists->anyBlocksDelivery($event->listedOn);
        $listNames = $this->blocklists->describeAll($event->listedOn);

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::IpBlacklisted,
            severity: $blocksDelivery ? AlertSeverity::Critical : AlertSeverity::Warning,
            title: $this->title($event->ipAddress, $domain->domain, $blocksDelivery),
            message: $this->message($event->ipAddress, $domain->domain, $listNames, $blocksDelivery, $sender),
            data: [
                'ip_address' => $event->ipAddress,
                'sending_host' => $sender?->hostname,
                'operated_by' => $sender?->organization,
                'listed_on' => $event->listedOn,
                'blocks_delivery' => $blocksDelivery,
                'delisting_urls' => array_values(array_filter(array_map(
                    $this->blocklists->delistUrl(...),
                    $event->listedOn,
                ))),
            ],
        );
    }

    private function title(string $ip, string $domain, bool $blocksDelivery): string
    {
        return $blocksDelivery
            ? sprintf('A server sending mail for %s (%s) is blocklisted', $domain, $ip)
            : sprintf('A server sending mail for %s (%s) was listed on an advisory blocklist', $domain, $ip);
    }

    private function message(
        string $ip,
        string $domain,
        string $listNames,
        bool $blocksDelivery,
        ?KnownSender $sender,
    ): string {
        $parts = [];

        // Lead with what the address is and why we were looking at it — that is
        // the question the bare-IP version left the reader to answer alone.
        $parts[] = sprintf(
            '%s is one of the servers that sends email for %s — we see it in your DMARC reports%s. It is now listed on %s.',
            $ip,
            $domain,
            $this->identify($sender),
            $listNames,
        );

        $parts[] = $blocksDelivery
            ? 'Mailbox providers query this blocklist while accepting mail, so messages sent from this address may be rejected or filtered to spam until it is removed.'
            : 'This is an advisory list that most mailbox providers do not consult when accepting mail, so your delivery is probably unaffected. It is worth understanding why the address was listed, but it is not an emergency.';

        $parts[] = $this->advice($sender);

        $parts[] = 'This is not about your MX records or your DNS setup — those control mail coming in, and this is about a server sending mail out.';

        return implode("\n\n", $parts);
    }

    private function identify(?KnownSender $sender): string
    {
        if (null !== $sender?->organization && '' !== $sender->organization) {
            return sprintf(' as %s', $sender->organization);
        }

        if (null !== $sender?->hostname && '' !== $sender->hostname) {
            return sprintf(' as %s', $sender->hostname);
        }

        return '';
    }

    /**
     * Delisting advice depends on who operates the address, because for most
     * customers the answer is "not you". Telling the operator of a shared ESP
     * relay to go and file a removal request sends them somewhere they have no
     * standing, and the honest action — check whether your own mail is affected,
     * then raise it with your provider — is a different task entirely.
     */
    private function advice(?KnownSender $sender): string
    {
        $operator = $sender?->organization;

        if (null !== $operator && '' !== $operator) {
            return sprintf(
                'This address is operated by %s, not by you, so you cannot request removal yourself — shared outbound relays are listed because of traffic from other customers as often as your own. Check whether your own delivery is actually suffering, and if it is, raise it with %s quoting the address and the list.',
                $operator,
                $operator,
            );
        }

        return 'If you operate this server, find and stop the source of the traffic that got it listed before requesting removal — a delisting that is not accompanied by a fix is usually reversed within days. If it belongs to your email provider, contact them rather than the blocklist.';
    }
}

<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One *sender* that showed up on a report for the first time — not one address.
 *
 * The distinction is the whole point (DEC-059 D5). Seznam rotates an IPv6 pool,
 * so an alert keyed on the address announced `mxb-1-908`, then `mxb-2-904`, then
 * `mxb-3-514`, forever: thirteen `new_unknown_sender` alerts in thirty days,
 * eleven of them on a single day, every one of them the customer's own relay.
 * Grouped on {@see ResolvedSender::identityKey()} the entire pool is one line:
 * "seznam.cz".
 */
final readonly class NewSenderAlertGroup
{
    /**
     * @param list<string> $sourceIps every address seen under this identity in the report
     */
    public function __construct(
        public string $identityKey,
        public string $label,
        public SenderRole $role,
        public int $messageCount,
        public array $sourceIps,
    ) {
    }

    /**
     * How the sender reads in alert copy: a name a human can act on, plus the
     * volume that says whether it is worth acting on at all. A raw address only
     * appears when reverse DNS gave us nothing better to say.
     */
    public function describe(): string
    {
        return sprintf(
            '%s (%d %s)',
            $this->label,
            $this->messageCount,
            1 === $this->messageCount ? 'message' : 'messages',
        );
    }

    /**
     * @return array{identity: string, label: string, role: string, messages: int, source_ips: list<string>}
     */
    public function toAlertData(): array
    {
        return [
            'identity' => $this->identityKey,
            'label' => $this->label,
            'role' => $this->role->value,
            'messages' => $this->messageCount,
            'source_ips' => $this->sourceIps,
        ];
    }
}

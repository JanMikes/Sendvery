<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * A record that has to be REMOVED before the record a guided setup step hands
 * over can be published — the one case where "here is the value, paste it" is
 * not the whole job.
 *
 * One exists today: a domain switching to managed DMARC keeps its own `_dmarc`
 * TXT record until the customer deletes it, and DNS (RFC 1034 §3.6.2) forbids a
 * CNAME from coexisting with any other record at the same name. Handing over the
 * CNAME without saying so produced the complaint that started this — "it does
 * not check there is existing dmarc txt and does not instruct me to delete it".
 *
 * Unlike a {@see SetupCaution}, which informs, this BLOCKS: the step's own
 * record cannot work until it is done, so the surface renders it as step 1 of 2
 * rather than as a footnote.
 */
final readonly class SetupPrerequisite
{
    /**
     * @param string      $key           stable identifier, also the `data-testid` on the rendered block
     * @param string      $title         imperative headline for the first of the two steps
     * @param string      $explanation   why this record has to go, and what is NOT lost by deleting it
     * @param string      $followUpTitle headline for the second step, so the ordering is spelled out rather than implied
     * @param string|null $currentValue  the record as published today, so the user deletes the right one
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $explanation,
        public string $followUpTitle,
        public DnsRecordAction $action,
        public string $recordType,
        public string $recordName,
        public string $recordFqdn,
        public ?string $currentValue,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Value\DmarcAlignment;
use App\Value\ForwardingAttestation;
use App\Value\Reports\DomainAlignment;
use App\Value\SenderAuthSignals;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Gathers one report's per-sender authentication evidence (DEC-060 WP-A/WP-B).
 *
 * Both writers on the ingest path — {@see SenderDiscovery} and
 * {@see \App\MessageHandler\AlertOnNewSender} — used to build this themselves
 * from near-identical GROUP BY queries. Two copies of the evidence-gathering
 * behind one classifier is a standing invitation for the alert and the
 * inventory to disagree about what a sender is, and the copies had already
 * started to diverge. There is one copy now.
 *
 * The rows are grouped finer than "per IP" because the two strongest signals
 * are *per identifier*, not per host: whether a DKIM signature aligned depends
 * on which domain signed for which From header, and that comparison needs the
 * organisational-domain rule ({@see DomainAlignment}) rather than SQL. So the
 * query returns one row per distinct (address, From domain, DKIM domain,
 * envelope domain) and the folding happens here.
 */
final readonly class ReportSenderSignals
{
    public function __construct(
        private Connection $database,
        private EnvelopeRewriteRegistry $envelopeRewriteRegistry,
    ) {
    }

    /**
     * @param list<string> $authorizedIps addresses the caller's team has
     *                                    vouched for; scoping is the caller's
     *                                    business, not this reader's
     *
     * @return array<string, SenderAuthSignals> keyed by source IP
     */
    public function forReport(UuidInterface $reportId, array $authorizedIps = []): array
    {
        $rows = $this->database->executeQuery(
            'SELECT
                rec.source_ip,
                rec.header_from,
                rec.dkim_domain,
                rec.spf_domain,
                dr.policy_adkim,
                dr.policy_aspf,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = :pass THEN rec.count ELSE 0 END) AS dkim_pass_count,
                SUM(CASE WHEN rec.spf_result = :pass THEN rec.count ELSE 0 END) AS spf_pass_count,
                -- FILTERed so the overwhelmingly common "no receiver annotated
                -- anything" case selects NULL rather than one empty array per
                -- record; compared as text because a malformed value must
                -- degrade to "no attestation", where json_array_length() would
                -- raise and abort the whole report transaction.
                json_agg(rec.policy_override_reasons)
                    FILTER (WHERE rec.policy_override_reasons::text <> \'[]\') AS policy_override_reasons
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            WHERE rec.dmarc_report_id = :reportId
            GROUP BY rec.source_ip, rec.header_from, rec.dkim_domain, rec.spf_domain, dr.policy_adkim, dr.policy_aspf',
            [
                'reportId' => $reportId->toString(),
                'pass' => 'pass',
            ],
        )->fetchAllAssociative();

        $totals = [];

        foreach ($rows as $row) {
            $sourceIp = (string) $row['source_ip'];
            $messages = (int) $row['total_messages'];
            $dkimPassed = (int) $row['dkim_pass_count'];

            $totals[$sourceIp] ??= [
                'messages' => 0,
                'dkim' => 0,
                'spf' => 0,
                'alignedDkim' => 0,
                'rewritten' => 0,
                'reasons' => [],
            ];

            $totals[$sourceIp]['messages'] += $messages;
            $totals[$sourceIp]['dkim'] += $dkimPassed;
            $totals[$sourceIp]['spf'] += (int) $row['spf_pass_count'];

            if ($this->dkimAligned($row, $dkimPassed)) {
                $totals[$sourceIp]['alignedDkim'] += $dkimPassed;
            }

            if ($this->envelopeRewritten($row)) {
                $totals[$sourceIp]['rewritten'] += $messages;
            }

            if (is_string($row['policy_override_reasons'])) {
                $totals[$sourceIp]['reasons'][] = $row['policy_override_reasons'];
            }
        }

        $signals = [];

        foreach ($totals as $sourceIp => $total) {
            $signals[$sourceIp] = SenderAuthSignals::fromCounts(
                dkimPassed: $total['dkim'],
                spfPassed: $total['spf'],
                totalMessages: $total['messages'],
                isAuthorized: in_array($sourceIp, $authorizedIps, true),
                forwarding: $this->attestation($total['reasons']),
                alignedDkimPassed: $total['alignedDkim'],
                rewrittenEnvelopeMessages: $total['rewritten'],
            );
        }

        return $signals;
    }

    /**
     * Whether this row's passing signatures were made *for the From domain*.
     *
     * A row with no passing signature contributes nothing whatever its domains
     * say — the point of the tier-A rule is a signature that verified, and an
     * aligned `d=` on a signature that failed is not one.
     *
     * @param array<string, mixed> $row
     */
    private function dkimAligned(array $row, int $dkimPassed): bool
    {
        if ($dkimPassed <= 0) {
            return false;
        }

        $dkimDomain = $row['dkim_domain'];
        $headerFrom = $row['header_from'];

        if (!is_string($dkimDomain) || !is_string($headerFrom)) {
            return false;
        }

        return DomainAlignment::isAligned($dkimDomain, $headerFrom, $this->alignmentMode($row['policy_adkim']));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function envelopeRewritten(array $row): bool
    {
        $spfDomain = $row['spf_domain'];
        $headerFrom = $row['header_from'];

        if (!is_string($spfDomain) || '' === $spfDomain || !is_string($headerFrom)) {
            return false;
        }

        // An aligned envelope is the sender's own return path, however it is
        // spelled. `bounces.example.com` under a From of `example.com` is a
        // perfectly ordinary bounce mailbox, not a relay's rewrite.
        if (DomainAlignment::isAligned($spfDomain, $headerFrom, $this->alignmentMode($row['policy_aspf']))) {
            return false;
        }

        return $this->envelopeRewriteRegistry->looksRewritten($spfDomain);
    }

    /**
     * RFC 7489 §6.3 makes relaxed the default for both `adkim` and `aspf`, so
     * an unreadable column falls back to it rather than inventing strictness a
     * domain never asked for.
     */
    private function alignmentMode(mixed $raw): DmarcAlignment
    {
        return is_string($raw) ? DmarcAlignment::tryFrom($raw) ?? DmarcAlignment::Relaxed : DmarcAlignment::Relaxed;
    }

    /**
     * @param list<string> $aggregatedJson one json_agg payload per grouped row
     */
    private function attestation(array $aggregatedJson): ForwardingAttestation
    {
        foreach ($aggregatedJson as $json) {
            $attestation = ForwardingAttestation::fromAggregatedJson($json);

            if ($attestation->attestsForwarding) {
                return $attestation;
            }
        }

        return ForwardingAttestation::none();
    }
}

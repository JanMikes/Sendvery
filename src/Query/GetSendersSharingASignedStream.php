<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Cross-receiver correlation (DEC-060 WP-C): which of these addresses are
 * carrying a signed stream that also passes from somewhere else?
 *
 * The shape commercial platforms describe: the same message stream passing from
 * one address and failing from another is forwarding. If `d=example.com`
 * verifies from address A and the same `d=` shows up failing from address B in
 * the same period, B is relaying A's mail rather than inventing it — the
 * signature broke on the hop, which is what a relay does to a signature.
 *
 * **Deliberately not restricted to a different reporting receiver**, despite
 * the name the technique goes by. The inference is about the stream, not about
 * who watched it: two receivers seeing the same relay is not better evidence
 * than one receiver seeing it twice, and requiring two would drop the signal
 * for every domain whose mail reaches one large mailbox provider.
 *
 * **What it cannot do.** Every field in a *failing* record is chosen by whoever
 * sent it, `d=` included. A spoofer can put `d=victim.com` in a signature that
 * verifies against nothing, and the victim's own legitimate mail then supplies
 * the passing half of the correlation for free. So this is corroboration and
 * nothing more — {@see \App\Services\SenderRoleClassifier} may use it to
 * withhold an accusation and may never use it to withhold an alert. That
 * restriction is encoded there, not left to rule ordering.
 */
final readonly class GetSendersSharingASignedStream
{
    /**
     * How far back to look for the passing half of the pair.
     *
     * Aggregate reports cover a day and arrive days late, so the origin of a
     * forwarded message routinely lands in a different report from the forward
     * itself. A week is wide enough to hold both and narrow enough that a
     * signing domain retired a month ago cannot vouch for anything today.
     */
    public const int CORRELATION_WINDOW_DAYS = 7;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $sourceIps addresses to test; the result is a subset
     *
     * @return list<string> the addresses whose DKIM signing domain also passed
     *                      from a *different* address inside the window
     */
    public function forDomain(string $domainId, \DateTimeImmutable $windowEnd, array $sourceIps): array
    {
        if ([] === $sourceIps) {
            return [];
        }

        return array_map(strval(...), $this->database->executeQuery(
            'SELECT DISTINCT candidate.source_ip
            FROM dmarc_record candidate
            JOIN dmarc_report candidate_report ON candidate_report.id = candidate.dmarc_report_id
            WHERE candidate_report.monitored_domain_id = :domainId
              AND candidate_report.date_range_end BETWEEN :windowStart AND :windowEnd
              AND candidate.source_ip IN (:sourceIps)
              AND candidate.dkim_domain IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM dmarc_record origin
                  JOIN dmarc_report origin_report ON origin_report.id = origin.dmarc_report_id
                  WHERE origin_report.monitored_domain_id = :domainId
                    AND origin_report.date_range_end BETWEEN :windowStart AND :windowEnd
                    AND origin.source_ip <> candidate.source_ip
                    AND origin.dkim_result = :pass
                    AND lower(origin.dkim_domain) = lower(candidate.dkim_domain)
              )',
            [
                'domainId' => $domainId,
                'windowStart' => $windowEnd->modify(sprintf('-%d days', self::CORRELATION_WINDOW_DAYS))->format('Y-m-d H:i:s'),
                'windowEnd' => $windowEnd->format('Y-m-d H:i:s'),
                'sourceIps' => $sourceIps,
                'pass' => 'pass',
            ],
            [
                'sourceIps' => ArrayParameterType::STRING,
            ],
        )->fetchFirstColumn());
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DmarcReportProcessed;
use App\Repository\MonitoredDomainRepository;
use App\Services\AlertEngine;
use App\Services\SenderIdentityResolver;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\NewSenderAlertGroup;
use App\Value\ResolvedSender;
use App\Value\SenderAuthSignals;
use App\Value\SenderRole;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fires once, the first time a *sender* the team has never seen starts sending
 * as one of their domains.
 *
 * WHY it stays a one-shot rather than re-firing while a sender remains
 * unreviewed: "a server we have never seen before started sending as you" is a
 * point-in-time event, and an event alert that repeats stops being read. The
 * standing condition — "senders are still waiting for your decision" — is now
 * covered twice over, by the weekly digest's review section and by
 * `sendvery:senders:review-reminder`, both of which read real
 * `known_sender.is_authorized` state and are volume-gated and deduped. Making
 * this handler re-fire on top of those would be a third voice saying the same
 * thing on every single report ingest.
 *
 * WHY the *definition* of "new" changed (DEC-059 §3.6). In thirty days of
 * production this was the single largest alert category — thirteen alerts,
 * eleven of them on one day — and not one described a real event. Three
 * separate faults produced that:
 *
 *  - **D5**: identity was the raw address, so a rotating IPv6 relay pool was an
 *    inexhaustible supply of "new senders". Senders now group by
 *    {@see ResolvedSender::identityKey()} — the registrable domain of the PTR —
 *    so fifteen Seznam addresses are one identity, `seznam.cz`.
 *  - **D6**: "seen before" was scoped to the one domain, so a relay trusted on
 *    `sendvery.com` since July 3rd was announced as brand new on a sibling
 *    domain of the same team. The lookback is now team-wide.
 *  - **D9**: on a domain's first-ever report nothing has been seen before, so
 *    *every* address qualified and day one opened with "5 new senders
 *    detected". The first report now establishes the baseline in silence.
 *
 * And a fourth: an alert is only worth interrupting somebody for if the sender
 * is actually unexplained. Own relays, recognised providers and forwarders are
 * ordinary mail flow and belong in the weekly digest as line items, so
 * {@see SenderRole::warrantsAlert()} gates the whole thing.
 */
#[AsMessageHandler]
final readonly class AlertOnNewSender
{
    /**
     * How many senders the alert names before it summarises the rest. Grouping
     * by identity means overflowing this now takes a genuinely unusual report,
     * rather than one relay rotating its pool.
     */
    private const int MAX_NAMED_SENDERS = 5;

    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private SenderIdentityResolver $senderIdentityResolver,
        private Connection $database,
    ) {
    }

    public function __invoke(DmarcReportProcessed $event): void
    {
        $domain = $this->monitoredDomainRepository->get($event->domainId);

        // D9. A domain with no history has no baseline, so everything looks new
        // and the customer's first ever impression of Sendvery is a warning
        // about their own mail. Note this cannot be read off
        // `monitored_domain.first_report_at`: ProcessDmarcReportHandler stamps
        // that before the event is dispatched, so by the time we run it is
        // always set.
        if (!$this->hasEarlierReport($event->domainId, $event->reportId)) {
            return;
        }

        $signalsByIp = $this->sendersInReport($domain->team->id, $event->reportId);

        if ([] === $signalsByIp) {
            return;
        }

        $resolvedByIp = $this->senderIdentityResolver->resolveMany(array_keys($signalsByIp), $signalsByIp);
        $candidates = $this->groupByIdentity($resolvedByIp, $signalsByIp);

        $newSenders = [];

        foreach ($this->identitiesSeenBefore($domain->team->id, $event->reportId, array_keys($candidates)) as $seen) {
            unset($candidates[$seen]);
        }

        foreach ($candidates as $candidate) {
            if ($candidate->role->warrantsAlert()) {
                $newSenders[] = $candidate;
            }
        }

        if ([] === $newSenders) {
            return;
        }

        $count = count($newSenders);
        $named = array_slice($newSenders, 0, self::MAX_NAMED_SENDERS);
        $remaining = $count - count($named);

        $summary = implode(', ', array_map(
            static fn (NewSenderAlertGroup $sender): string => $sender->describe(),
            $named,
        )).($remaining > 0 ? sprintf(' and %d more', $remaining) : '');

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::NewUnknownSender,
            severity: AlertSeverity::Warning,
            title: $this->fitTitle(
                1 === $count
                    ? "New sender for {$domain->domain}: {$newSenders[0]->label}"
                    : "{$count} new senders detected for {$domain->domain}",
            ),
            message: sprintf(
                'First time your team has seen %s sending as %s. %s',
                $summary,
                $domain->domain,
                $this->reviewSentence($count),
            ),
            data: [
                'new_senders' => array_map(
                    static fn (NewSenderAlertGroup $sender): array => $sender->toAlertData(),
                    $newSenders,
                ),
                'report_id' => $event->reportId->toString(),
                'reporter_org' => $event->reporterOrg,
            ],
        );
    }

    /**
     * `alert.title` is a VARCHAR(255) and the sender name inside it now comes
     * from a PTR record — text written by whoever controls the reverse zone.
     * Overflowing the column would abort the flush, and since the whole report
     * ingest shares that transaction, every retry would abort at exactly the
     * same place. A truncated title is a cosmetic problem; a poisoned report
     * that can never be ingested is not.
     */
    private function fitTitle(string $title): string
    {
        return mb_strlen($title) <= 255 ? $title : mb_substr($title, 0, 254).'…';
    }

    private function reviewSentence(int $count): string
    {
        return 1 === $count
            ? 'It is listed as "Needs review" until you authorize it or mark it not authorized — nothing is blocked either way.'
            : 'They are listed as "Needs review" until you authorize them or mark them not authorized — nothing is blocked either way.';
    }

    private function hasEarlierReport(UuidInterface $domainId, UuidInterface $reportId): bool
    {
        return false !== $this->database->fetchOne(
            'SELECT 1 FROM dmarc_report WHERE monitored_domain_id = :domainId AND id != :reportId LIMIT 1',
            [
                'domainId' => $domainId->toString(),
                'reportId' => $reportId->toString(),
            ],
        );
    }

    /**
     * The report's addresses with the evidence needed to classify each one.
     *
     * `is_authorized` is read across the whole team for the same reason the
     * lookback is: the operator vouching for a relay on one domain is a verdict
     * about the relay, not about that domain.
     *
     * @return array<string, SenderAuthSignals> keyed by source IP
     */
    private function sendersInReport(UuidInterface $teamId, UuidInterface $reportId): array
    {
        $rows = $this->database->executeQuery(
            'SELECT
                rec.source_ip,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = :pass THEN rec.count ELSE 0 END) AS dkim_pass_count,
                SUM(CASE WHEN rec.spf_result = :pass THEN rec.count ELSE 0 END) AS spf_pass_count
            FROM dmarc_record rec
            WHERE rec.dmarc_report_id = :reportId
            GROUP BY rec.source_ip',
            [
                'reportId' => $reportId->toString(),
                'pass' => 'pass',
            ],
        )->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $sourceIps = array_map(static fn (array $row): string => (string) $row['source_ip'], $rows);
        $authorized = $this->addressesTheTeamVouchedFor($teamId, $sourceIps);

        $signals = [];

        foreach ($rows as $row) {
            $sourceIp = (string) $row['source_ip'];

            $signals[$sourceIp] = SenderAuthSignals::fromCounts(
                dkimPassed: (int) $row['dkim_pass_count'],
                spfPassed: (int) $row['spf_pass_count'],
                totalMessages: (int) $row['total_messages'],
                isAuthorized: in_array($sourceIp, $authorized, true),
            );
        }

        return $signals;
    }

    /**
     * @param list<string> $sourceIps
     *
     * @return list<string>
     */
    private function addressesTheTeamVouchedFor(UuidInterface $teamId, array $sourceIps): array
    {
        return array_map(strval(...), $this->database->executeQuery(
            'SELECT DISTINCT ks.source_ip
            FROM known_sender ks
            JOIN monitored_domain md ON md.id = ks.monitored_domain_id
            WHERE md.team_id = :teamId AND ks.is_authorized = true AND ks.source_ip IN (:sourceIps)',
            [
                'teamId' => $teamId->toString(),
                'sourceIps' => $sourceIps,
            ],
            [
                'sourceIps' => ArrayParameterType::STRING,
            ],
        )->fetchFirstColumn());
    }

    /**
     * Identity keys this team has already received mail from, on any of their
     * domains, in any earlier report.
     *
     * The key is built in SQL exactly the way {@see ResolvedSender::identityKey()}
     * builds it in PHP, joining the shared `sender_identity` cache that ingest
     * populates. An address that predates the cache has no row yet and so keys
     * on itself — the conservative outcome, because the worst it can do is let
     * one already-known relay be announced once more, after which the cache is
     * warm and it never happens again.
     *
     * Deliberately *not* `SenderIdentitySql::IDENTITY_KEY`: that expression
     * falls back through `rec.resolved_org` for the dashboard, where grouping a
     * little too coarsely costs nothing. Here the key is compared against one
     * computed in PHP, so an organisation name ("Seznam") where the other side
     * has a registrable domain ("seznam.cz") would simply fail to match and
     * announce a known relay as new.
     *
     * @param list<string> $identityKeys
     *
     * @return list<string>
     */
    private function identitiesSeenBefore(UuidInterface $teamId, UuidInterface $reportId, array $identityKeys): array
    {
        return array_map(strval(...), $this->database->executeQuery(
            'SELECT DISTINCT COALESCE(si.registrable_domain, si.hostname, rec.source_ip) AS identity_key
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN sender_identity si ON si.source_ip = rec.source_ip
            WHERE md.team_id = :teamId
            AND dr.id != :reportId
            AND COALESCE(si.registrable_domain, si.hostname, rec.source_ip) IN (:identityKeys)',
            [
                'teamId' => $teamId->toString(),
                'reportId' => $reportId->toString(),
                'identityKeys' => $identityKeys,
            ],
            [
                'identityKeys' => ArrayParameterType::STRING,
            ],
        )->fetchFirstColumn());
    }

    /**
     * Collapses the report's addresses into senders.
     *
     * A group takes the *least* alarming role any of its addresses carries.
     * That is the whole point of grouping: the question this alert answers is
     * "is this sender known to you?", so an identity with one recognised address
     * is a recognised identity. Scoring a pool by its worst member would put D5
     * straight back — the team authorizes the pool addresses they have seen, the
     * relay rotates in a fresh one, and it alerts again. Volumes are summed so
     * the copy reports what the sender sent, not what one address of it sent.
     *
     * @param array<string, ResolvedSender>    $resolvedByIp
     * @param array<string, SenderAuthSignals> $signalsByIp
     *
     * @return array<string, NewSenderAlertGroup> keyed by identity key
     */
    private function groupByIdentity(array $resolvedByIp, array $signalsByIp): array
    {
        $labels = [];
        $roles = [];
        $messageCounts = [];
        $sourceIps = [];

        foreach ($resolvedByIp as $sourceIp => $resolved) {
            $key = $resolved->identityKey();

            $labels[$key] ??= $resolved->displayLabel();
            $messageCounts[$key] = ($messageCounts[$key] ?? 0) + $signalsByIp[$sourceIp]->totalMessages;
            $sourceIps[$key][] = $sourceIp;

            $known = $roles[$key] ?? null;

            if (null === $known || ($known->warrantsAlert() && !$resolved->role->warrantsAlert())) {
                $roles[$key] = $resolved->role;
            }
        }

        $groups = [];

        foreach ($labels as $key => $label) {
            $groups[$key] = new NewSenderAlertGroup(
                identityKey: $key,
                label: $label,
                role: $roles[$key],
                messageCount: $messageCounts[$key],
                sourceIps: $sourceIps[$key],
            );
        }

        return $groups;
    }
}

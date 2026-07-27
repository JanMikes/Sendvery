<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DmarcReportProcessed;
use App\Repository\MonitoredDomainRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fires once, the first time an address is seen sending as a domain.
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
 */
#[AsMessageHandler]
final readonly class AlertOnNewSender
{
    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(DmarcReportProcessed $event): void
    {
        $domain = $this->monitoredDomainRepository->get($event->domainId);

        // Find source IPs in this report that haven't been seen in any previous report for this domain
        $newSenders = $this->database->executeQuery(
            'SELECT DISTINCT rec.source_ip
             FROM dmarc_record rec
             JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
             WHERE dr.id = :reportId
             AND rec.source_ip NOT IN (
                 SELECT DISTINCT prev_rec.source_ip
                 FROM dmarc_record prev_rec
                 JOIN dmarc_report prev_dr ON prev_dr.id = prev_rec.dmarc_report_id
                 WHERE prev_dr.monitored_domain_id = :domainId
                 AND prev_dr.id != :reportId
             )',
            [
                'reportId' => $event->reportId->toString(),
                'domainId' => $event->domainId->toString(),
            ],
        )->fetchFirstColumn();

        if ([] === $newSenders) {
            return;
        }

        $senderList = implode(', ', array_slice($newSenders, 0, 5));
        $count = count($newSenders);
        $suffix = $count > 5 ? sprintf(' and %d more', $count - 5) : '';

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::NewUnknownSender,
            severity: AlertSeverity::Warning,
            title: "{$count} new sender(s) detected for {$domain->domain}",
            message: "New source IPs sending email as {$domain->domain}: {$senderList}{$suffix}. They are listed as \"Needs review\" until you authorize them or mark them not authorized — nothing is blocked either way.",
            data: [
                'new_sender_ips' => $newSenders,
                'report_id' => $event->reportId->toString(),
                'reporter_org' => $event->reporterOrg,
            ],
        );
    }
}

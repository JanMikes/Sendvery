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

#[AsMessageHandler]
final readonly class AlertOnFailureSpike
{
    private const float SPIKE_THRESHOLD = 20.0;

    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(DmarcReportProcessed $event): void
    {
        $totalMessages = $event->passCount + $event->failCount;

        if (0 === $totalMessages) {
            return;
        }

        $currentFailRate = ($event->failCount / $totalMessages) * 100;

        $averageFailRate = $this->getAverageFailRate($event->domainId->toString(), $event->reportId->toString());

        if (null === $averageFailRate) {
            return;
        }

        $spike = $currentFailRate - $averageFailRate;

        if ($spike <= self::SPIKE_THRESHOLD) {
            return;
        }

        $domain = $this->monitoredDomainRepository->get($event->domainId);

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Critical,
            title: "Failure spike detected for {$domain->domain}",
            message: sprintf(
                'DMARC failure rate for %s spiked to %.1f%% (average: %.1f%%). Report from %s shows %d failures out of %d messages.',
                $domain->domain,
                $currentFailRate,
                $averageFailRate,
                $event->reporterOrg,
                $event->failCount,
                $totalMessages,
            ),
            data: [
                'current_fail_rate' => round($currentFailRate, 1),
                'average_fail_rate' => round($averageFailRate, 1),
                'spike_amount' => round($spike, 1),
                'fail_count' => $event->failCount,
                'pass_count' => $event->passCount,
                'total_messages' => $totalMessages,
                'report_id' => $event->reportId->toString(),
                'reporter_org' => $event->reporterOrg,
            ],
        );
    }

    private function getAverageFailRate(string $domainId, string $excludeReportId): ?float
    {
        $result = $this->database->executeQuery(
            'SELECT
                SUM(CASE WHEN rec.dkim_result != :pass AND rec.spf_result != :pass THEN rec.count ELSE 0 END)::float
                / NULLIF(SUM(rec.count), 0)
                * 100 AS avg_fail_rate
             FROM dmarc_record rec
             JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
             WHERE dr.monitored_domain_id = :domainId
             AND dr.id != :excludeReportId',
            [
                'domainId' => $domainId,
                'excludeReportId' => $excludeReportId,
                'pass' => 'pass',
            ],
        )->fetchOne();

        return null !== $result && false !== $result ? (float) $result : null;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DmarcReportProcessed;
use App\Repository\MonitoredDomainRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\DmarcPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecommendPolicyUpgrade
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

        if (DmarcPolicy::Reject === $domain->dmarcPolicy) {
            return;
        }

        $stats = $this->getPassRateStats($event->domainId->toString(), $domain->dmarcPolicy);

        if (null === $stats) {
            return;
        }

        // Already have a recent recommendation? Skip.
        $recentRecommendation = $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
             WHERE monitored_domain_id = :domainId
             AND type = :type
             AND created_at > NOW() - INTERVAL \'30 days\'',
            [
                'domainId' => $event->domainId->toString(),
                'type' => AlertType::PolicyRecommendation->value,
            ],
        )->fetchOne();

        if ((int) $recentRecommendation > 0) {
            return;
        }

        if (DmarcPolicy::None === $domain->dmarcPolicy && $stats['pass_rate'] >= 95.0 && $stats['days'] >= 30) {
            $this->alertEngine->createAlert(
                team: $domain->team,
                monitoredDomain: $domain,
                type: AlertType::PolicyRecommendation,
                severity: AlertSeverity::Info,
                title: "Consider upgrading DMARC policy for {$domain->domain}",
                message: sprintf(
                    'Your domain %s has maintained a %.1f%% DMARC pass rate over %d days with p=none. Consider upgrading to p=quarantine to start enforcing your DMARC policy.',
                    $domain->domain,
                    $stats['pass_rate'],
                    $stats['days'],
                ),
                data: [
                    'current_policy' => 'none',
                    'recommended_policy' => 'quarantine',
                    'pass_rate' => $stats['pass_rate'],
                    'monitoring_days' => $stats['days'],
                ],
            );

            return;
        }

        if (DmarcPolicy::Quarantine === $domain->dmarcPolicy && $stats['pass_rate'] >= 99.0 && $stats['days'] >= 60) {
            $this->alertEngine->createAlert(
                team: $domain->team,
                monitoredDomain: $domain,
                type: AlertType::PolicyRecommendation,
                severity: AlertSeverity::Info,
                title: "Ready to upgrade to p=reject for {$domain->domain}",
                message: sprintf(
                    'Your domain %s has maintained a %.1f%% DMARC pass rate over %d days with p=quarantine. Consider upgrading to p=reject for maximum protection.',
                    $domain->domain,
                    $stats['pass_rate'],
                    $stats['days'],
                ),
                data: [
                    'current_policy' => 'quarantine',
                    'recommended_policy' => 'reject',
                    'pass_rate' => $stats['pass_rate'],
                    'monitoring_days' => $stats['days'],
                ],
            );
        }
    }

    /**
     * @return array{pass_rate: float, days: int}|null
     */
    private function getPassRateStats(string $domainId, ?DmarcPolicy $currentPolicy): ?array
    {
        $result = $this->database->executeQuery(
            'SELECT
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0) * 100,
                    0
                ) AS pass_rate,
                COALESCE(
                    EXTRACT(DAY FROM NOW() - MIN(dr.date_range_begin)),
                    0
                )::int AS days
             FROM dmarc_record rec
             JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
             WHERE dr.monitored_domain_id = :domainId',
            [
                'domainId' => $domainId,
                'pass' => 'pass',
            ],
        )->fetchAssociative();

        if (false === $result || 0 === (int) $result['days']) {
            return null;
        }

        return [
            'pass_rate' => (float) $result['pass_rate'],
            'days' => (int) $result['days'],
        ];
    }
}

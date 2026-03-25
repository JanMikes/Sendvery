<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class SenderDiscovery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $database,
        private IdentityProvider $identityProvider,
        private OrganizationMapper $organizationMapper,
        private ClockInterface $clock,
    ) {
    }

    public function updateFromReport(MonitoredDomain $domain, UuidInterface $reportId): void
    {
        $records = $this->database->executeQuery(
            'SELECT
                rec.source_ip,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END) AS pass_count
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            WHERE dr.id = :reportId
            GROUP BY rec.source_ip',
            [
                'reportId' => $reportId->toString(),
                'pass' => 'pass',
            ],
        )->fetchAllAssociative();

        $now = $this->clock->now();

        foreach ($records as $record) {
            $sourceIp = $record['source_ip'];
            $messageCount = (int) $record['total_messages'];
            $passCount = (int) $record['pass_count'];

            $existing = $this->findExistingSender($domain->id, $sourceIp);

            if (null !== $existing) {
                $newTotal = $existing['total_messages'] + $messageCount;
                $existingPassMessages = (int) round($existing['total_messages'] * $existing['pass_rate'] / 100);
                $newPassRate = $newTotal > 0 ? ($existingPassMessages + $passCount) / $newTotal * 100 : 0.0;

                $this->database->executeStatement(
                    'UPDATE known_sender SET
                        last_seen_at = :lastSeenAt,
                        total_messages = :totalMessages,
                        pass_rate = :passRate
                    WHERE id = :id',
                    [
                        'id' => $existing['id'],
                        'lastSeenAt' => $now->format('Y-m-d H:i:s'),
                        'totalMessages' => $newTotal,
                        'passRate' => round($newPassRate, 2),
                    ],
                );
            } else {
                $hostname = $this->resolveHostname($sourceIp);
                $organization = null !== $hostname ? $this->organizationMapper->resolve($hostname) : null;
                $passRate = $messageCount > 0 ? $passCount / $messageCount * 100 : 0.0;

                $sender = new KnownSender(
                    id: $this->identityProvider->nextIdentity(),
                    monitoredDomain: $domain,
                    sourceIp: $sourceIp,
                    firstSeenAt: $now,
                    lastSeenAt: $now,
                    totalMessages: $messageCount,
                    passRate: round($passRate, 2),
                    hostname: $hostname,
                    organization: $organization,
                );

                $this->entityManager->persist($sender);
            }
        }
    }

    /**
     * @return array{id: string, total_messages: int, pass_rate: float}|null
     */
    private function findExistingSender(UuidInterface $domainId, string $sourceIp): ?array
    {
        $row = $this->database->executeQuery(
            'SELECT id, total_messages, pass_rate FROM known_sender WHERE monitored_domain_id = :domainId AND source_ip = :sourceIp',
            [
                'domainId' => $domainId->toString(),
                'sourceIp' => $sourceIp,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'total_messages' => (int) $row['total_messages'],
            'pass_rate' => (float) $row['pass_rate'],
        ];
    }

    private function resolveHostname(string $ip): ?string
    {
        $hostname = @gethostbyaddr($ip);

        if (false === $hostname || $hostname === $ip) {
            return null;
        }

        return $hostname;
    }
}

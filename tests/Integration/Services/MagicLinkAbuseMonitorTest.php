<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\MagicLinkToken;
use App\Repository\MagicLinkTokenRepository;
use App\Services\MagicLinkAbuseMonitor;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;

/**
 * The per-email cap protects one mailbox; this monitor watches the axis the
 * July 2026 abuse campaign actually used — hundreds of DISTINCT addresses at
 * a slow drip. When the global hourly volume crosses the threshold, ops must
 * hear about it, because every request past that point is Sendvery mailing a
 * stranger.
 */
final class MagicLinkAbuseMonitorTest extends IntegrationTestCase
{
    #[Test]
    public function staysSilentAtNormalVolume(): void
    {
        $now = new \DateTimeImmutable();
        $this->createTokens(3, $now->modify('-30 minutes'));

        $logger = $this->spyLogger();
        $monitor = new MagicLinkAbuseMonitor($this->getService(MagicLinkTokenRepository::class), $logger);

        $monitor->recordRequest('someone@example.com', '203.0.113.5', 'Mozilla/5.0', $now);

        self::assertSame([], $logger->records, 'A handful of sign-ins per hour is normal traffic — alerting on it would train ops to ignore the alarm that matters.');
    }

    #[Test]
    public function alertsWhenHourlyVolumeCrossesThreshold(): void
    {
        $now = new \DateTimeImmutable();
        $this->createTokens(MagicLinkAbuseMonitor::HOURLY_ALERT_THRESHOLD, $now->modify('-30 minutes'));

        $logger = $this->spyLogger();
        $monitor = new MagicLinkAbuseMonitor($this->getService(MagicLinkTokenRepository::class), $logger);

        $monitor->recordRequest('victim@example.com', '203.0.113.5', 'Mozilla/5.0 (Windows NT 6.1)', $now);

        self::assertCount(1, $logger->records, 'Crossing the hourly volume threshold must raise exactly one ops alarm for this request.');
        self::assertSame('error', $logger->records[0]['level'], 'The anomaly must log at error level — lower levels never reach anyone.');
        self::assertStringContainsString('volume anomaly', (string) $logger->records[0]['message']);
        self::assertSame('victim@example.com', $logger->records[0]['context']['email'] ?? null, 'The alert must carry the triggering email so the investigation can start from the alert itself.');
        self::assertSame('203.0.113.5', $logger->records[0]['context']['requested_ip'] ?? null, 'The alert must carry the source IP — that is what gets banned.');
    }

    #[Test]
    public function ignoresVolumeOlderThanOneHour(): void
    {
        $now = new \DateTimeImmutable();
        $this->createTokens(MagicLinkAbuseMonitor::HOURLY_ALERT_THRESHOLD + 5, $now->modify('-2 hours'));

        $logger = $this->spyLogger();
        $monitor = new MagicLinkAbuseMonitor($this->getService(MagicLinkTokenRepository::class), $logger);

        $monitor->recordRequest('someone@example.com', null, null, $now);

        self::assertSame([], $logger->records, 'Yesterday\'s spike must not page today — the monitor watches a rolling hour, not all-time volume.');
    }

    private function createTokens(int $count, \DateTimeImmutable $createdAt): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        for ($i = 0; $i < $count; ++$i) {
            $em->persist(new MagicLinkToken(
                id: Uuid::uuid7(),
                email: 'volume-'.Uuid::uuid7()->toString().'@example.com',
                token: bin2hex(random_bytes(32)),
                expiresAt: $createdAt->modify('+15 minutes'),
                createdAt: $createdAt,
            ));
        }

        $em->flush();
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: string, message: string|\Stringable, context: array<mixed>}>}
     */
    private function spyLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string|\Stringable, context: array<mixed>}> */
            public array $records = [];

            /**
             * @param mixed[] $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => $message, 'context' => $context];
            }
        };
    }
}

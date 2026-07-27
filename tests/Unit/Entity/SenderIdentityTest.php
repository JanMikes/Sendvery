<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SenderIdentity;
use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SenderIdentityTest extends TestCase
{
    #[Test]
    public function startsAsAnUnresolvedRowAwaitingItsFirstLookup(): void
    {
        $identity = $this->newIdentity();

        self::assertFalse($identity->isResolved());
        self::assertSame(0, $identity->resolutionAttempts);
        self::assertNull($identity->lastAttemptAt);
        self::assertSame(SenderRole::Unknown, $identity->role);
        self::assertTrue($identity->isDueForRetry(new \DateTimeImmutable('2026-07-27 09:00:00')));
    }

    #[Test]
    public function storesTheFactsAReverseLookupProduced(): void
    {
        $identity = $this->newIdentity();

        $identity->recordResolution(
            hostname: 'eu.cloud-sec-av.com',
            registrableDomain: 'cloud-sec-av.com',
            organization: null,
            role: SenderRole::Forwarder,
            at: new \DateTimeImmutable('2026-07-27 10:00:00'),
        );

        self::assertTrue($identity->isResolved());
        self::assertSame('eu.cloud-sec-av.com', $identity->hostname);
        self::assertSame('cloud-sec-av.com', $identity->registrableDomain);
        self::assertSame(SenderRole::Forwarder, $identity->role);
        self::assertSame('2026-07-27 10:00:00', $identity->resolvedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-27 10:00:00', $identity->lastAttemptAt?->format('Y-m-d H:i:s'));
        self::assertSame(1, $identity->resolutionAttempts);
    }

    #[Test]
    public function neverLooksUpAHostThatAlreadyAnswered(): void
    {
        $identity = $this->newIdentity();
        $identity->recordResolution('mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp, new \DateTimeImmutable('2026-07-27 10:00:00'));

        self::assertFalse(
            $identity->isDueForRetry(new \DateTimeImmutable('2027-07-27 10:00:00')),
            'PTR records for mail infrastructure are effectively static; re-querying them buys nothing and risks a stall.',
        );
    }

    #[Test]
    public function remembersThatAnAddressHasNoReverseRecord(): void
    {
        $identity = $this->newIdentity();

        $identity->recordResolution(null, null, null, SenderRole::Unknown, new \DateTimeImmutable('2026-07-27 10:00:00'));

        self::assertFalse($identity->isResolved());
        self::assertSame(1, $identity->resolutionAttempts);
        self::assertSame(
            '2026-07-27 10:00:00',
            $identity->lastAttemptAt?->format('Y-m-d H:i:s'),
            'The failed attempt has to be recorded, otherwise every ingest re-queries an address that will never answer.',
        );
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function backoffProvider(): array
    {
        return [
            'first failure waits an hour' => [1, '2026-07-27 10:59:59', '2026-07-27 11:00:00'],
            'second failure waits six hours' => [2, '2026-07-27 15:59:59', '2026-07-27 16:00:00'],
            'third failure waits a day' => [3, '2026-07-28 09:59:59', '2026-07-28 10:00:00'],
            'further failures settle at a week' => [4, '2026-08-03 09:59:59', '2026-08-03 10:00:00'],
            'backoff stops growing after the last step' => [9, '2026-08-03 09:59:59', '2026-08-03 10:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('backoffProvider')]
    public function backsOffFurtherAfterEachFailedLookup(int $attempts, string $tooSoon, string $dueNow): void
    {
        $identity = new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '192.0.2.10',
            resolvedAt: new \DateTimeImmutable('2026-07-27 10:00:00'),
            resolutionAttempts: $attempts,
            lastAttemptAt: new \DateTimeImmutable('2026-07-27 10:00:00'),
        );

        self::assertFalse($identity->isDueForRetry(new \DateTimeImmutable($tooSoon)));
        self::assertTrue($identity->isDueForRetry(new \DateTimeImmutable($dueNow)));
    }

    private function newIdentity(): SenderIdentity
    {
        return new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '192.0.2.10',
            resolvedAt: new \DateTimeImmutable('2026-07-27 09:00:00'),
        );
    }
}

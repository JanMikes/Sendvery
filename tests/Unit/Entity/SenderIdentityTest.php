<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SenderIdentity;
use App\Value\Dns\AsnRegistration;
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
        self::assertFalse($identity->isForwardConfirmed());
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
            forwardConfirmed: true,
            at: new \DateTimeImmutable('2026-07-27 10:00:00'),
        );

        self::assertTrue($identity->isResolved());
        self::assertSame('eu.cloud-sec-av.com', $identity->hostname);
        self::assertSame('cloud-sec-av.com', $identity->registrableDomain);
        self::assertSame(SenderRole::Forwarder, $identity->role);
        self::assertTrue($identity->isForwardConfirmed());
        self::assertSame('2026-07-27 10:00:00', $identity->resolvedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-27 10:00:00', $identity->lastAttemptAt?->format('Y-m-d H:i:s'));
        self::assertSame(1, $identity->resolutionAttempts);
    }

    #[Test]
    public function neverLooksUpAHostThatAlreadyAnswered(): void
    {
        $identity = $this->newIdentity();
        $identity->recordResolution('mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp, true, new \DateTimeImmutable('2026-07-27 10:00:00'));
        $identity->recordAsnLookup(new AsnRegistration(43037, 'SEZNAM-AS'), new \DateTimeImmutable('2026-07-27 10:00:00'));

        self::assertFalse(
            $identity->isDueForRetry(new \DateTimeImmutable('2027-07-27 10:00:00')),
            'PTR records for mail infrastructure are effectively static; re-querying them buys nothing and risks a stall.',
        );
    }

    #[Test]
    public function neverLooksUpAHostWhoseNameWasCheckedAndFoundWanting(): void
    {
        $identity = $this->newIdentity();
        $identity->recordResolution('claims.mimecast.com', 'mimecast.com', null, SenderRole::Unknown, false, new \DateTimeImmutable('2026-07-27 10:00:00'));
        $identity->recordAsnLookup(null, new \DateTimeImmutable('2026-07-27 10:00:00'));

        self::assertFalse($identity->isForwardConfirmed());
        self::assertFalse(
            $identity->isDueForRetry(new \DateTimeImmutable('2027-07-27 10:00:00')),
            'A failed confirmation is an answer, not an open question.',
        );
    }

    #[Test]
    public function remembersThatAnAddressIsAnnouncedByNobody(): void
    {
        $identity = $this->newIdentity();
        $identity->recordResolution('mail.nowhere.example', 'nowhere.example', null, SenderRole::Unknown, true, new \DateTimeImmutable('2026-07-27 10:00:00'));
        $identity->recordAsnLookup(null, new \DateTimeImmutable('2026-07-27 10:00:00'));

        self::assertNull($identity->asn);
        self::assertNull($identity->asnRegistration());
        self::assertFalse(
            $identity->isDueForRetry(new \DateTimeImmutable('2027-07-27 10:00:00')),
            '"Announced by nobody" is an answer, and caching it is what stops every ingest asking again.',
        );
    }

    #[Test]
    public function asksOnceMoreAboutARowCachedBeforeTheNetworkAxisExisted(): void
    {
        $identity = new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '52.212.19.177',
            resolvedAt: new \DateTimeImmutable('2026-07-20 10:00:00'),
            hostname: 'eu.cloud-sec-av.com',
            registrableDomain: 'cloud-sec-av.com',
            role: SenderRole::Forwarder,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-20 10:00:00'),
            forwardConfirmed: true,
        );

        self::assertTrue(
            $identity->isDueForRetry(new \DateTimeImmutable('2026-07-27 10:00:00')),
            'The row is complete by the old definition and would otherwise never be revisited, leaving the new axis empty forever.',
        );
    }

    #[Test]
    public function keepsTheNetworkThatAnnouncesAnAddress(): void
    {
        $identity = $this->newIdentity();
        $identity->recordAsnLookup(new AsnRegistration(16509, 'AMAZON-02'), new \DateTimeImmutable('2026-07-27 10:00:00'));

        self::assertSame(16509, $identity->asn);
        self::assertSame('AMAZON-02', $identity->asnOrganization);
        self::assertSame('AS16509 AMAZON-02', $identity->asnRegistration()?->label());
    }

    #[Test]
    public function asksOnceMoreAboutAHostnameCachedBeforeConfirmationExisted(): void
    {
        $identity = new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '15.222.110.90',
            resolvedAt: new \DateTimeImmutable('2026-07-20 10:00:00'),
            hostname: 'ca.cloud-sec-av.com',
            registrableDomain: 'cloud-sec-av.com',
            role: SenderRole::Forwarder,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-20 10:00:00'),
        );

        self::assertFalse(
            $identity->isForwardConfirmed(),
            'A question never asked is not a yes; a row cached before the check existed has earned no trust.',
        );
        self::assertTrue(
            $identity->isDueForRetry(new \DateTimeImmutable('2026-07-27 10:00:00')),
            'Freezing these rows as unconfirmed would demote genuine forwarders forever, so each gets one chance to answer.',
        );
    }

    #[Test]
    public function remembersThatAnAddressHasNoReverseRecord(): void
    {
        $identity = $this->newIdentity();

        $identity->recordResolution(null, null, null, SenderRole::Unknown, null, new \DateTimeImmutable('2026-07-27 10:00:00'));

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

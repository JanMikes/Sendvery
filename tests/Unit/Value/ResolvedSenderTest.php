<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Entity\SenderIdentity;
use App\Value\ResolvedSender;
use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ResolvedSenderTest extends TestCase
{
    #[Test]
    public function groupsRotatingAddressesOfOneRelayUnderASingleIdentity(): void
    {
        $first = new ResolvedSender('2a02:598:1::1', 'mxb-1-a01.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);
        $second = new ResolvedSender('2a02:598:2::9', 'mxb-2-904.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);

        self::assertSame(
            $first->identityKey(),
            $second->identityKey(),
            'One relay behind a rotating address pool is one sender, not two.',
        );
    }

    #[Test]
    public function fallsBackToTheHostnameWhenTheRegistrableDomainCannotBeDerived(): void
    {
        $sender = new ResolvedSender('192.0.2.10', 'localhost', null, null, SenderRole::Unknown);

        self::assertSame('localhost', $sender->identityKey());
    }

    #[Test]
    public function keepsAnUnresolvableSenderGroupedByItsOwnAddress(): void
    {
        $sender = ResolvedSender::unresolved('192.0.2.10');

        self::assertSame(
            '192.0.2.10',
            $sender->identityKey(),
            'Unresolvable senders must stay distinct rather than merging into a shared bucket.',
        );
        self::assertFalse($sender->isResolved());
        self::assertSame(SenderRole::Unknown, $sender->role);
    }

    #[Test]
    public function prefersTheCuratedOrganisationNameForDisplay(): void
    {
        $sender = new ResolvedSender('77.75.76.89', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);

        self::assertSame('Seznam', $sender->displayLabel());
    }

    #[Test]
    public function showsTheRegistrableDomainForGatewaysNobodyHasMappedYet(): void
    {
        $sender = new ResolvedSender('52.212.19.177', 'eu.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);

        self::assertSame(
            'cloud-sec-av.com',
            $sender->displayLabel(),
            'An unmapped gateway is still far more useful to a user than a raw IP.',
        );
    }

    #[Test]
    public function showsTheHostnameWhenOnlyTheHostnameIsKnown(): void
    {
        $sender = new ResolvedSender('192.0.2.10', 'localhost', null, null, SenderRole::Unknown);

        self::assertSame('localhost', $sender->displayLabel());
    }

    #[Test]
    public function showsTheRawAddressOnlyWhenThereIsNothingElseToSay(): void
    {
        self::assertSame('192.0.2.10', ResolvedSender::unresolved('192.0.2.10')->displayLabel());
    }

    #[Test]
    public function carriesTheCachedFactsOfAnIdentityAcross(): void
    {
        $identity = new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '3.132.108.44',
            resolvedAt: new \DateTimeImmutable('2026-07-27 09:00:00'),
            hostname: 'ipw-outbound.inkyphishfence.com',
            registrableDomain: 'inkyphishfence.com',
            organization: null,
            role: SenderRole::Forwarder,
        );

        $sender = ResolvedSender::fromIdentity($identity);

        self::assertSame('3.132.108.44', $sender->sourceIp);
        self::assertSame('ipw-outbound.inkyphishfence.com', $sender->hostname);
        self::assertSame('inkyphishfence.com', $sender->registrableDomain);
        self::assertNull($sender->organization);
        self::assertSame(SenderRole::Forwarder, $sender->role);
        self::assertTrue($sender->isResolved());
    }

    #[Test]
    public function letsTheCallerOverrideTheGlobalRoleWithItsOwnVerdict(): void
    {
        $identity = new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '77.75.76.89',
            resolvedAt: new \DateTimeImmutable('2026-07-27 09:00:00'),
            hostname: 'mxb.seznam.cz',
            registrableDomain: 'seznam.cz',
            organization: 'Seznam',
            role: SenderRole::Esp,
        );

        $sender = ResolvedSender::fromIdentity($identity, SenderRole::OwnRelay);

        self::assertSame(
            SenderRole::OwnRelay,
            $sender->role,
            'A team that authorized this IP sees its own relay; the shared cache still says "provider".',
        );
        self::assertSame(SenderRole::Esp, $identity->role, 'The cached row must not be rewritten by one team.');
    }
}

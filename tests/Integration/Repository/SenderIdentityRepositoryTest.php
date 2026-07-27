<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\SenderIdentity;
use App\Repository\SenderIdentityRepository;
use App\Tests\IntegrationTestCase;
use App\Value\SenderRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class SenderIdentityRepositoryTest extends IntegrationTestCase
{
    public function testFindsACachedIdentityByItsAddress(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);

        $repository->add($this->identity('77.75.76.89', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp));
        $em->flush();
        $em->clear();

        $found = $repository->findByIp('77.75.76.89');

        self::assertNotNull($found);
        self::assertSame('mxb.seznam.cz', $found->hostname);
        self::assertSame('seznam.cz', $found->registrableDomain);
        self::assertSame('Seznam', $found->organization);
        self::assertSame(SenderRole::Esp, $found->role);
    }

    public function testReturnsNullForAnAddressNobodyHasSeen(): void
    {
        $repository = $this->getService(SenderIdentityRepository::class);

        self::assertNull($repository->findByIp('198.51.100.77'));
    }

    public function testResolvesAWholeReportsWorthOfAddressesInOneLookup(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);

        $repository->add($this->identity('77.75.76.90', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp));
        $repository->add($this->identity('52.212.19.178', 'eu.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder));
        $em->flush();
        $em->clear();

        $found = $repository->findByIps(['77.75.76.90', '52.212.19.178', '198.51.100.99']);

        self::assertCount(2, $found, 'Addresses with no cached identity are simply absent.');
        self::assertSame('seznam.cz', $found['77.75.76.90']->registrableDomain);
        self::assertSame('cloud-sec-av.com', $found['52.212.19.178']->registrableDomain);
        self::assertArrayNotHasKey('198.51.100.99', $found);
    }

    public function testToleratesTheSameAddressAppearingTwiceInABatch(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);

        $repository->add($this->identity('77.75.78.91', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp));
        $em->flush();
        $em->clear();

        $found = $repository->findByIps(['77.75.78.91', '77.75.78.91']);

        self::assertCount(1, $found);
    }

    public function testAsksNothingOfTheDatabaseForAnEmptyBatch(): void
    {
        $repository = $this->getService(SenderIdentityRepository::class);

        self::assertSame([], $repository->findByIps([]));
    }

    public function testFindsAnIdentityDiscoveredEarlierInTheSameTransaction(): void
    {
        $repository = $this->getService(SenderIdentityRepository::class);

        $repository->add($this->identity('198.51.100.42', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp));

        $found = $repository->findByIp('198.51.100.42');

        self::assertNotNull(
            $found,
            'Handlers never flush, so two handlers of the same report event would each create a row for the same new address and the closing flush would fail.',
        );
        self::assertSame('seznam.cz', $found->registrableDomain);
    }

    public function testMixesAlreadyStoredAndJustDiscoveredIdentitiesInOneBatch(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);

        $repository->add($this->identity('198.51.100.43', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp));
        $em->flush();
        $em->clear();

        $repository->add($this->identity('198.51.100.44', 'eu.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder));

        $found = $repository->findByIps(['198.51.100.43', '198.51.100.44']);

        self::assertCount(2, $found);
        self::assertSame('seznam.cz', $found['198.51.100.43']->registrableDomain);
        self::assertSame('cloud-sec-av.com', $found['198.51.100.44']->registrableDomain);
    }

    public function testKeepsOnlyOneIdentityPerAddress(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);

        $repository->add($this->identity('203.0.113.10', 'mail.example.com', 'example.com', null, SenderRole::Unknown));
        $em->flush();

        $repository->add($this->identity('203.0.113.10', 'mail.example.com', 'example.com', null, SenderRole::Unknown));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->flush();
    }

    private function identity(
        string $sourceIp,
        string $hostname,
        string $registrableDomain,
        ?string $organization,
        SenderRole $role,
    ): SenderIdentity {
        return new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: $sourceIp,
            resolvedAt: new \DateTimeImmutable('2026-07-27 10:00:00'),
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-27 10:00:00'),
        );
    }
}

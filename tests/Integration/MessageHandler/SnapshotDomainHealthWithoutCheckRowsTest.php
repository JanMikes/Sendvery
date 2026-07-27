<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Message\SnapshotDomainHealth;
use App\MessageHandler\SnapshotDomainHealthHandler;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A health snapshot is a graded VERDICT about a domain — an A–F letter and a
 * 0-100 score, publishable at `/health/{shareHash}` to anyone with the link.
 * It may therefore only be recorded once a DNS check has actually produced a
 * result to grade.
 *
 * Snapshotting a domain with no stored check rows scored every protocol 0 and
 * stamped it grade F, manufacturing a definite failure out of "we have not
 * looked yet". That is reachable in production: the on-add path enqueues the
 * DNS check and the snapshot onto the same transport and relies on FIFO
 * ordering, but the check retries with backoff and concurrent workers can
 * invert the pair.
 */
final class SnapshotDomainHealthWithoutCheckRowsTest extends IntegrationTestCase
{
    #[Test]
    public function noSnapshotIsRecordedForADomainWhoseDnsHasNeverBeenChecked(): void
    {
        [$domain, $em] = $this->domain();

        ($this->getService(SnapshotDomainHealthHandler::class))(
            new SnapshotDomainHealth(domainId: $domain->id),
        );
        $em->flush();

        self::assertSame(
            0,
            $this->countSnapshots($domain),
            'A domain with no DNS check results has nothing to grade. Recording a snapshot here publishes an F for a domain nobody has checked.',
        );
    }

    #[Test]
    public function aSingleCheckResultIsEnoughToRecordASnapshot(): void
    {
        [$domain, $em] = $this->domain();
        $this->persistCheck($em, $domain, DnsCheckType::Dmarc, 'v=DMARC1; p=none', true);

        ($this->getService(SnapshotDomainHealthHandler::class))(
            new SnapshotDomainHealth(domainId: $domain->id),
        );
        $em->flush();

        self::assertSame(
            1,
            $this->countSnapshots($domain),
            'Once any check has run there is a real result to grade, so the snapshot must still be written.',
        );
    }

    /**
     * @return array{0: MonitoredDomain, 1: EntityManagerInterface}
     */
    private function domain(): array
    {
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        assert(null !== $persona->domain);

        $em = $this->getService(EntityManagerInterface::class);
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        assert(null !== $domain);

        return [$domain, $em];
    }

    private function countSnapshots(MonitoredDomain $domain): int
    {
        return (int) $this->getService(EntityManagerInterface::class)
            ->createQuery(
                'SELECT COUNT(s.id) FROM '.DomainHealthSnapshot::class.' s WHERE s.monitoredDomain = :domain',
            )
            ->setParameter('domain', $domain->id->toString())
            ->getSingleScalarResult();
    }

    private function persistCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}

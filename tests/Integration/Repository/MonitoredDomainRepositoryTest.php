<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Exceptions\MonitoredDomainNotFound;
use App\Repository\MonitoredDomainRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class MonitoredDomainRepositoryTest extends IntegrationTestCase
{
    public function testGetReturnsDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repo = $this->getService(MonitoredDomainRepository::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Repo Test',
            slug: 'repo-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'repo-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $found = $repo->get($domainId);
        self::assertSame($domainId->toString(), $found->id->toString());
        self::assertSame('repo-test.com', $found->domain);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $repo = $this->getService(MonitoredDomainRepository::class);

        $this->expectException(MonitoredDomainNotFound::class);
        $repo->get(Uuid::uuid7());
    }

    public function testFindByDomainReturnsDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repo = $this->getService(MonitoredDomainRepository::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Find Test',
            slug: 'find-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'find-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $found = $repo->findByDomain('find-test.com', $teamId);
        self::assertNotNull($found);
        self::assertSame('find-test.com', $found->domain);
    }

    public function testFindByDomainReturnsNullWhenNotFound(): void
    {
        $repo = $this->getService(MonitoredDomainRepository::class);

        $result = $repo->findByDomain('nonexistent.com', Uuid::uuid7());
        self::assertNull($result);
    }
}

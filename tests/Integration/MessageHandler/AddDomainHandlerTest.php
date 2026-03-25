<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Message\AddDomain;
use App\MessageHandler\AddDomainHandler;
use App\Repository\MonitoredDomainRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AddDomainHandlerTest extends IntegrationTestCase
{
    public function testCreatesDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AddDomainHandler::class);
        $domainRepo = $this->getService(MonitoredDomainRepository::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Add Domain Test',
            slug: 'add-domain-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $domainId = Uuid::uuid7();
        $handler(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: 'new-domain.com',
        ));
        $em->flush();

        $domain = $domainRepo->get($domainId);
        self::assertSame('new-domain.com', $domain->domain);
        self::assertSame($teamId->toString(), $domain->team->id->toString());
    }

    public function testNormalizesDomainName(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AddDomainHandler::class);
        $domainRepo = $this->getService(MonitoredDomainRepository::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Normalize Test',
            slug: 'normalize-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $domainId = Uuid::uuid7();
        $handler(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: '  EXAMPLE.COM  ',
        ));
        $em->flush();

        $domain = $domainRepo->get($domainId);
        self::assertSame('example.com', $domain->domain);
    }
}

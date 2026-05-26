<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Events\DomainAdded;
use App\Message\AddDomain;
use App\MessageHandler\AddDomainHandler;
use App\MessageHandler\PublishAuthorizationRecordWhenDomainAdded;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\FakeDnsRecordPublisher;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PublishAuthorizationRecordWhenDomainAddedTest extends IntegrationTestCase
{
    public function testPublishesAuthorizationRecordOnDomainAdd(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $addHandler = $this->getService(AddDomainHandler::class);
        $domainRepo = $this->getService(MonitoredDomainRepository::class);
        $fakePublisher = $this->getService(FakeDnsRecordPublisher::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Auth Record Test',
            slug: 'auth-record-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $domainId = Uuid::uuid7();
        $addHandler(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: 'acme.org',
        ));
        $em->flush();

        $domain = $domainRepo->get($domainId);
        self::assertNotNull($domain->cloudflareAuthRecordId, 'The handler must persist the Cloudflare record ID on the entity when publishing succeeds.');
        self::assertTrue($fakePublisher->authorizationRecordExists('acme.org'), 'The handler must publish the authorization record via the DnsRecordPublisher.');
    }

    public function testGracefullyHandlesPublisherFailure(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $addHandler = $this->getService(AddDomainHandler::class);
        $domainRepo = $this->getService(MonitoredDomainRepository::class);
        $fakePublisher = $this->getService(FakeDnsRecordPublisher::class);

        $fakePublisher->simulateFailure();

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Auth Fail Test',
            slug: 'auth-fail-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $domainId = Uuid::uuid7();
        $addHandler(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: 'fail.example',
        ));
        $em->flush();

        $domain = $domainRepo->get($domainId);
        self::assertNull($domain->cloudflareAuthRecordId, 'When the publisher fails, the handler must not crash and must leave the record ID null for the sync cron to retry.');

        $fakePublisher->simulateSuccess();
    }

    public function testSkipsMissingDomainGracefully(): void
    {
        $publishHandler = $this->getService(PublishAuthorizationRecordWhenDomainAdded::class);

        $publishHandler(new DomainAdded(Uuid::uuid7(), Uuid::uuid7()));

        $this->expectNotToPerformAssertions();
    }
}

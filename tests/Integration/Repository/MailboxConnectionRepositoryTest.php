<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MailboxConnection;
use App\Entity\Team;
use App\Exceptions\MailboxConnectionNotFound;
use App\Repository\MailboxConnectionRepository;
use App\Tests\IntegrationTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class MailboxConnectionRepositoryTest extends IntegrationTestCase
{
    public function testGetReturnsConnection(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(MailboxConnectionRepository::class);

        $team = $this->createTeam($em);
        $connection = $this->createConnection($em, $team);
        $em->flush();
        $em->clear();

        $found = $repository->get($connection->id);

        self::assertSame($connection->id->toString(), $found->id->toString());
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $repository = $this->getService(MailboxConnectionRepository::class);

        $this->expectException(MailboxConnectionNotFound::class);
        $repository->get(Uuid::uuid7());
    }

    public function testFindActiveConnections(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(MailboxConnectionRepository::class);

        $team = $this->createTeam($em);

        $active = $this->createConnection($em, $team);

        $inactive = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'inactive.example.com',
            port: 993,
            encryptedUsername: 'enc',
            encryptedPassword: 'enc',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            isActive: false,
        );
        $em->persist($inactive);
        $em->flush();
        $em->clear();

        $connections = $repository->findActiveConnections();

        $ids = array_map(static fn (MailboxConnection $c): string => $c->id->toString(), $connections);
        self::assertContains($active->id->toString(), $ids);
        self::assertNotContains($inactive->id->toString(), $ids);
    }

    public function testFindByTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(MailboxConnectionRepository::class);

        $team1 = $this->createTeam($em, 'team-1');
        $team2 = $this->createTeam($em, 'team-2');

        $conn1 = $this->createConnection($em, $team1);
        $this->createConnection($em, $team2);
        $em->flush();
        $em->clear();

        $connections = $repository->findByTeam($team1->id);

        self::assertCount(1, $connections);
        self::assertSame($conn1->id->toString(), $connections[0]->id->toString());
    }

    private function createTeam(EntityManagerInterface $em, string $suffix = ''): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team '.$suffix,
            slug: 'test-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        return $team;
    }

    private function createConnection(EntityManagerInterface $em, Team $team): MailboxConnection
    {
        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'encrypted-user',
            encryptedPassword: 'encrypted-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($connection);

        return $connection;
    }
}

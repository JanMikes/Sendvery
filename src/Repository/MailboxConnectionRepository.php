<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailboxConnection;
use App\Exceptions\MailboxConnectionNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class MailboxConnectionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): MailboxConnection
    {
        $connection = $this->entityManager->find(MailboxConnection::class, $id);

        if (null === $connection) {
            throw new MailboxConnectionNotFound(sprintf('Mailbox connection with ID "%s" not found.', $id->toString()));
        }

        return $connection;
    }

    /** @return array<MailboxConnection> */
    public function findActiveConnections(): array
    {
        return $this->entityManager->getRepository(MailboxConnection::class)->findBy([
            'isActive' => true,
        ]);
    }

    /** @return array<MailboxConnection> */
    public function findByTeam(UuidInterface $teamId): array
    {
        return $this->entityManager->getRepository(MailboxConnection::class)->findBy([
            'team' => $teamId->toString(),
        ]);
    }
}

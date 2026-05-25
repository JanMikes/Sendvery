<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailboxConnection;
use App\Exceptions\MailboxConnectionNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

readonly class MailboxConnectionRepository
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

    /**
     * Mailboxes bound to a specific monitored domain. Used by
     * {@see \App\Services\Dns\RuaMailboxMatcher} so the 5th RUA destination
     * row on `/app/domains/{id}` can match the published rua= address against
     * the connected mailbox login without re-deriving the binding from the
     * matrix query.
     *
     * @return array<MailboxConnection>
     */
    public function findByDomain(UuidInterface $domainId): array
    {
        return $this->entityManager->getRepository(MailboxConnection::class)->findBy([
            'monitoredDomain' => $domainId->toString(),
        ]);
    }
}

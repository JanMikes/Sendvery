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

    /**
     * Cron-poller consumers. TASK-133 hides soft-deleted mailboxes from polling
     * (`disconnectedAt IS NULL`) so we never re-attempt IMAP login on a
     * disconnected row. Hard delete is intentionally avoided per the
     * `never-delete-user-data` memory.
     *
     * @return array<MailboxConnection>
     */
    public function findActiveConnections(): array
    {
        return $this->entityManager->getRepository(MailboxConnection::class)->findBy([
            'isActive' => true,
            'disconnectedAt' => null,
        ]);
    }

    /**
     * Dashboard list + `$hasMailbox` overview gating. TASK-133 hides soft-deleted
     * rows from the team-scoped list so the operator sees the truthful "active
     * mailboxes" count. The entity itself is still reachable via `get()` for
     * audit / late-arriving-report attribution.
     *
     * @return array<MailboxConnection>
     */
    public function findByTeam(UuidInterface $teamId): array
    {
        return $this->entityManager->getRepository(MailboxConnection::class)->findBy([
            'team' => $teamId->toString(),
            'disconnectedAt' => null,
        ]);
    }

    /**
     * Mailboxes bound to a specific monitored domain. Used by
     * {@see \App\Services\Dns\RuaMailboxMatcher} so the 5th RUA destination
     * row on `/app/domains/{id}` can match the published rua= address against
     * the connected mailbox login without re-deriving the binding from the
     * matrix query.
     *
     * Disconnected (soft-deleted) mailboxes are excluded — otherwise
     * `RuaMailboxMatcher` would still report a disconnected mailbox as
     * "matched" and the per-domain RUA destination row would keep showing
     * the green "Ingesting via mailbox" badge after the user clicked
     * Disconnect (TASK-133 cross-surface regression — same shape as TASK-114).
     *
     * @return array<MailboxConnection>
     */
    public function findByDomain(UuidInterface $domainId): array
    {
        return $this->entityManager->getRepository(MailboxConnection::class)->findBy([
            'monitoredDomain' => $domainId->toString(),
            'disconnectedAt' => null,
        ]);
    }
}

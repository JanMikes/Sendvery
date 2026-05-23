<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MailboxConnection;
use App\Message\ConnectMailbox;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\CredentialEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Persists a new mailbox connection. The handler is intentionally a pure
 * writer — it does NOT verify connectivity. Callers MUST run
 * `MailboxConnectionTester::test()` first and only dispatch on success,
 * otherwise we silently store broken rows that the next poll cron will
 * have to flag as errored. The wizard at
 * {@see \App\Controller\Dashboard\AddMailboxController} owns that
 * contract.
 */
#[AsMessageHandler]
final readonly class ConnectMailboxHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamRepository $teamRepository,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private CredentialEncryptor $encryptor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConnectMailbox $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $domain = null !== $message->domainId
            ? $this->monitoredDomainRepository->get($message->domainId)
            : null;

        $encryptedUsername = $this->encryptor->encrypt($message->username);
        $encryptedPassword = $this->encryptor->encrypt($message->password);

        $connection = new MailboxConnection(
            id: $message->connectionId,
            team: $team,
            type: $message->type,
            host: $message->host,
            port: $message->port,
            encryptedUsername: $encryptedUsername,
            encryptedPassword: $encryptedPassword,
            encryption: $message->encryption,
            createdAt: $this->clock->now(),
            monitoredDomain: $domain,
        );

        $this->entityManager->persist($connection);
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MailboxConnection;
use App\Message\ConnectMailbox;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\CredentialEncryptor;
use App\Services\Mail\MailClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ConnectMailboxHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamRepository $teamRepository,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private CredentialEncryptor $encryptor,
        private MailClient $mailClient,
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

        // Test the connection before saving
        $testResult = $this->mailClient->testConnection($connection);

        if (!$testResult->success) {
            $connection->markError($testResult->error ?? 'Connection test failed');
        }

        $this->entityManager->persist($connection);
    }
}

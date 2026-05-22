<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Value\ConnectionTestResult;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Production IMAP implementation. Holds a single Webklex client open across
 * fetch + move calls so a batch of 200 envelopes doesn't trigger 200 logins.
 *
 * Folder creation is best-effort: if Sendvery/Pending etc. don't exist, we
 * create them on first use. Seznam Email Profi supports nested folders via
 * standard IMAP namespacing.
 */
final class ImapCentralInboxClient implements CentralInboxClient
{
    private ?Client $client = null;

    public function __construct(
        private readonly CentralInboxConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<FetchedEnvelope> */
    public function fetchPending(): array
    {
        $client = $this->connect();
        $inbox = $this->openFolder($client, 'INBOX');

        $status = $inbox->status();
        $uidvalidity = isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null;

        $messages = $inbox->messages()
            ->unseen()
            ->limit($this->config->batchSize)
            ->get();

        $envelopes = [];

        foreach ($messages as $message) {
            assert($message instanceof Message);

            try {
                $size = strlen((string) $message->getRawBody());
                if ($size > $this->config->maxMessageBytes) {
                    $this->logger->warning('Skipping oversized message in central inbox ({size} bytes).', [
                        'size' => $size,
                        'limit' => $this->config->maxMessageBytes,
                        'uid' => $message->getUid(),
                    ]);
                    $this->moveMessage($message, CentralInboxFolder::Failed);

                    continue;
                }

                $envelopes[] = $this->envelopeFromMessage($message, $uidvalidity);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to read message from central inbox: {error}', [
                    'error' => $e->getMessage(),
                    'uid' => $message->getUid(),
                ]);
            }
        }

        return $envelopes;
    }

    public function moveToFolder(int $uid, CentralInboxFolder $folder): void
    {
        $client = $this->connect();
        $inbox = $this->openFolder($client, 'INBOX');

        try {
            $message = $inbox->messages()->getMessageByUid($uid);
        } catch (\Throwable $e) {
            $this->logger->info('Cannot move IMAP UID {uid}: {error}', ['uid' => $uid, 'error' => $e->getMessage()]);

            return;
        }

        $this->moveMessage($message, $folder);
    }

    public function close(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            $this->client->disconnect();
        } catch (\Throwable) {
            // Best effort. A failed disconnect just means the socket dies on its own.
        }

        $this->client = null;
    }

    public function testConnection(): ConnectionTestResult
    {
        try {
            $client = $this->connect();
            $inbox = $this->openFolder($client, 'INBOX');
            $status = $inbox->status();
            $count = isset($status['messages']) ? (int) $status['messages'] : 0;
            $this->close();

            return new ConnectionTestResult(success: true, error: null, mailboxCount: $count);
        } catch (ConnectionFailedException $e) {
            $this->close();

            return new ConnectionTestResult(success: false, error: $e->getMessage(), mailboxCount: 0);
        } catch (\Throwable $e) {
            $this->close();

            return new ConnectionTestResult(success: false, error: $e->getMessage(), mailboxCount: 0);
        }
    }

    private function connect(): Client
    {
        if (null !== $this->client && $this->client->isConnected()) {
            return $this->client;
        }

        $manager = new ClientManager();
        $client = $manager->make([
            'host' => $this->config->host,
            'port' => $this->config->port,
            'encryption' => $this->config->encryption->value,
            'validate_cert' => true,
            'username' => $this->config->username,
            'password' => $this->config->password,
            'protocol' => 'imap',
        ]);
        $client->connect();

        $this->client = $client;

        return $client;
    }

    private function openFolder(Client $client, string $path): Folder
    {
        $folder = $client->getFolderByPath($path);
        if (null === $folder) {
            throw new \RuntimeException(sprintf('IMAP folder "%s" not found.', $path));
        }

        return $folder;
    }

    private function moveMessage(Message $message, CentralInboxFolder $folder): void
    {
        $path = $this->config->folderPath($folder);
        $this->ensureFolderExists($path);
        $message->move($path);
    }

    private function ensureFolderExists(string $path): void
    {
        $client = $this->connect();
        if (null !== $client->getFolderByPath($path)) {
            return;
        }

        try {
            $client->createFolder($path, expunge: false);
            $this->logger->info('Created IMAP folder {path} in central inbox.', ['path' => $path]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to create IMAP folder {path}: {error}', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function envelopeFromMessage(Message $message, ?int $uidvalidity): FetchedEnvelope
    {
        $messageIdHeader = $message->getMessageId()->toString();
        $fallbackUid = (string) $message->getUid();
        $messageId = '' !== $messageIdHeader
            ? $messageIdHeader
            : sprintf('<no-header-%s.%s@central.sendvery>', $uidvalidity ?? 0, $fallbackUid);

        $fromAttribute = $message->getFrom();
        $fromFirst = $fromAttribute->first();
        $from = is_object($fromFirst) && property_exists($fromFirst, 'mail') ? (string) $fromFirst->mail : '';

        $dateAttribute = $message->getDate();
        $dateFirst = $dateAttribute->first();
        $receivedAt = is_object($dateFirst) && method_exists($dateFirst, 'toDate')
            ? \DateTimeImmutable::createFromInterface($dateFirst->toDate())
            : new \DateTimeImmutable();

        return new FetchedEnvelope(
            messageId: $messageId,
            fromAddress: $from,
            subject: $message->getSubject()->toString(),
            receivedAt: $receivedAt,
            rawEml: (string) $message->getRawBody(),
            uid: (int) $message->getUid(),
            uidvalidity: $uidvalidity,
        );
    }
}

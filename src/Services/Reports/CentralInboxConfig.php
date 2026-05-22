<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Value\MailboxEncryption;
use App\Value\Reports\CentralInboxFolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Env-driven configuration for the central reports@sendvery.com inbox.
 * Read once at service construction; missing values surface as boot-time
 * errors so production never silently runs with bad config.
 *
 * The inbox is considered enabled when SENDVERY_REPORTS_INBOX_USERNAME is
 * set — self-hosters that don't run a central inbox simply leave it blank.
 */
final readonly class CentralInboxConfig
{
    public bool $enabled;
    public string $host;
    public int $port;
    public string $username;
    public string $password;
    public MailboxEncryption $encryption;
    public string $pendingFolder;
    public string $processedFolder;
    public string $failedFolder;
    public string $junkFolder;
    public int $batchSize;
    public int $maxMessageBytes;

    public function __construct(
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_HOST')]
        string $host,
        #[Autowire(env: 'int:SENDVERY_REPORTS_INBOX_PORT')]
        int $port,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_USERNAME')]
        string $username,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_PASSWORD')]
        string $password,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_ENCRYPTION')]
        string $encryption,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_PENDING_FOLDER')]
        string $pendingFolder,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_PROCESSED_FOLDER')]
        string $processedFolder,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_FAILED_FOLDER')]
        string $failedFolder,
        #[Autowire(env: 'SENDVERY_REPORTS_INBOX_JUNK_FOLDER')]
        string $junkFolder,
        #[Autowire(env: 'int:SENDVERY_REPORTS_INBOX_BATCH_SIZE')]
        int $batchSize,
        #[Autowire(env: 'int:SENDVERY_REPORTS_INBOX_MAX_MESSAGE_BYTES')]
        int $maxMessageBytes,
    ) {
        $this->enabled = '' !== $username;
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = MailboxEncryption::from($encryption);
        $this->pendingFolder = $pendingFolder;
        $this->processedFolder = $processedFolder;
        $this->failedFolder = $failedFolder;
        $this->junkFolder = $junkFolder;
        $this->batchSize = $batchSize;
        $this->maxMessageBytes = $maxMessageBytes;
    }

    public function folderPath(CentralInboxFolder $folder): string
    {
        return match ($folder) {
            CentralInboxFolder::Pending => $this->pendingFolder,
            CentralInboxFolder::Processed => $this->processedFolder,
            CentralInboxFolder::Failed => $this->failedFolder,
            CentralInboxFolder::Junk => $this->junkFolder,
        };
    }
}

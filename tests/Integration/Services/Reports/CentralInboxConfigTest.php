<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Reports;

use App\Services\Reports\CentralInboxConfig;
use App\Tests\IntegrationTestCase;
use App\Value\MailboxEncryption;
use App\Value\Reports\CentralInboxFolder;

final class CentralInboxConfigTest extends IntegrationTestCase
{
    public function testHydratesFromEnv(): void
    {
        $config = $this->getService(CentralInboxConfig::class);

        self::assertTrue($config->enabled);
        self::assertSame('imap.fake.test', $config->host);
        self::assertSame(993, $config->port);
        self::assertSame('reports@sendvery.test', $config->username);
        self::assertSame(MailboxEncryption::Ssl, $config->encryption);
        self::assertSame('Sendvery/Pending', $config->pendingFolder);
        self::assertSame(50, $config->batchSize);
    }

    public function testFolderPathRoundTrip(): void
    {
        $config = $this->getService(CentralInboxConfig::class);

        self::assertSame('Sendvery/Pending', $config->folderPath(CentralInboxFolder::Pending));
        self::assertSame('Sendvery/Processed', $config->folderPath(CentralInboxFolder::Processed));
        self::assertSame('Sendvery/Failed', $config->folderPath(CentralInboxFolder::Failed));
        self::assertSame('Sendvery/Junk', $config->folderPath(CentralInboxFolder::Junk));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SyncHostedDmarcRecordsCommandTest extends IntegrationTestCase
{
    #[Test]
    public function republishesAHostedTxtWhoseContentHasDrifted(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function recreatesAMissingHostedTxt(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function deletesAStaleHostedTxtOnceItsCnameIsGone(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function neverDeletesAHostedTxtWhileTheCnameStillPointsAtUs(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }

    #[Test]
    public function doesNotCrossMatchReportDmarcAuthorizationRecords(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

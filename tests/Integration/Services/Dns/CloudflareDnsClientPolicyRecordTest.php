<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Dns;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class CloudflareDnsClientPolicyRecordTest extends IntegrationTestCase
{
    #[Test]
    public function changedContentIssuesAPatchAndLeavesExactlyOneTxt(): void
    {
        self::markTestIncomplete('Skeleton from TASK-174; implemented in its build task.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainSenderResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainSenderResultTest extends TestCase
{
    #[Test]
    public function itCanBeConstructed(): void
    {
        $result = new DomainSenderResult(
            sourceIp: '1.2.3.4',
            resolvedOrg: 'Google LLC',
            totalMessages: 500,
            passCount: 480,
            failCount: 20,
        );

        self::assertSame('1.2.3.4', $result->sourceIp);
        self::assertSame('Google LLC', $result->resolvedOrg);
        self::assertSame(500, $result->totalMessages);
        self::assertSame(480, $result->passCount);
        self::assertSame(20, $result->failCount);
    }

    #[Test]
    public function itCanBeCreatedFromDatabaseRow(): void
    {
        $result = DomainSenderResult::fromDatabaseRow([
            'source_ip' => '10.0.0.1',
            'resolved_org' => null,
            'total_messages' => '200',
            'pass_count' => '190',
            'fail_count' => '10',
        ]);

        self::assertSame('10.0.0.1', $result->sourceIp);
        self::assertNull($result->resolvedOrg);
        self::assertSame(200, $result->totalMessages);
        self::assertSame(190, $result->passCount);
        self::assertSame(10, $result->failCount);
    }
}

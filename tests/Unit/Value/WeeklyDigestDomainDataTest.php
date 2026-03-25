<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\WeeklyDigestDomainData;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestDomainDataTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $domain = new WeeklyDigestDomainData(
            domainName: 'example.com',
            totalMessages: 250,
            passRate: 98.2,
            passRateDelta: -1.5,
            newSenders: ['mailchimp.com', 'sendgrid.net'],
            alerts: [
                ['title' => 'New sender detected', 'severity' => 'info'],
            ],
        );

        self::assertSame('example.com', $domain->domainName);
        self::assertSame(250, $domain->totalMessages);
        self::assertSame(98.2, $domain->passRate);
        self::assertSame(-1.5, $domain->passRateDelta);
        self::assertSame(['mailchimp.com', 'sendgrid.net'], $domain->newSenders);
        self::assertCount(1, $domain->alerts);
    }

    public function testNullPassRateDelta(): void
    {
        $domain = new WeeklyDigestDomainData(
            domainName: 'new-domain.com',
            totalMessages: 0,
            passRate: 0.0,
            passRateDelta: null,
            newSenders: [],
            alerts: [],
        );

        self::assertNull($domain->passRateDelta);
    }
}

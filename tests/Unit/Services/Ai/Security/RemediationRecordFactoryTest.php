<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai\Security;

use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Security\RemediationRecordFactory;
use App\Services\ReportAddressProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RemediationRecordFactoryTest extends TestCase
{
    private RemediationRecordFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RemediationRecordFactory(new ReportAddressProvider('reports@sendvery.test'));
    }

    #[Test]
    public function spfFailureYieldsTheStrictBaselineRecord(): void
    {
        $records = $this->factory->buildFor(new DnsCheckFailure('SPF', 'Acme.example', 'no spf record'));

        self::assertCount(1, $records);
        self::assertSame('TXT', $records[0]->type);
        self::assertSame('acme.example', $records[0]->host);
        self::assertSame('v=spf1 -all', $records[0]->value);
    }

    #[Test]
    public function dmarcFailureYieldsARecordPointingReportsAtSendvery(): void
    {
        $records = $this->factory->buildFor(new DnsCheckFailure('dmarc', 'acme.example', 'missing'));

        self::assertCount(1, $records);
        self::assertSame('_dmarc.acme.example', $records[0]->host);
        self::assertStringContainsString('v=DMARC1', $records[0]->value);
        self::assertStringContainsString('rua=mailto:reports@sendvery.test', $records[0]->value);
    }

    #[Test]
    public function dkimAndMxYieldNoCopyableRecordBecauseTheValueIsNotDeterministic(): void
    {
        self::assertSame([], $this->factory->buildFor(new DnsCheckFailure('DKIM', 'acme.example', 'no key')));
        self::assertSame([], $this->factory->buildFor(new DnsCheckFailure('MX', 'acme.example', 'no mx')));
        self::assertSame([], $this->factory->buildFor(new DnsCheckFailure('SOMETHING', 'acme.example', '?')));
    }

    #[Test]
    public function aBlankDomainYieldsNothing(): void
    {
        self::assertSame([], $this->factory->buildFor(new DnsCheckFailure('SPF', '   ', 'x')));
    }
}

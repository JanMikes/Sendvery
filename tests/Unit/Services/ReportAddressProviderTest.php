<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ReportAddressProvider;
use PHPUnit\Framework\TestCase;

final class ReportAddressProviderTest extends TestCase
{
    public function testReturnsTheConfiguredAddress(): void
    {
        $provider = new ReportAddressProvider('reports@example.com');

        self::assertSame('reports@example.com', $provider->get());
    }

    public function testDerivesTheReportDomainFromTheAddress(): void
    {
        $provider = new ReportAddressProvider('reports@sendvery.test');

        self::assertSame('sendvery.test', $provider->getReportDomain());
    }

    public function testReportDomainIsNullWhenTheAddressHasNoAtSign(): void
    {
        $provider = new ReportAddressProvider('not-an-email');

        self::assertNull($provider->getReportDomain());
    }

    public function testReportDomainIsNullWhenThereIsNoDomainAfterTheAtSign(): void
    {
        $provider = new ReportAddressProvider('reports@');

        self::assertNull($provider->getReportDomain());
    }
}

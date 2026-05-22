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
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\BlacklistChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

final class BlacklistCheckerTest extends TestCase
{
    private BlacklistChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new BlacklistChecker();
        DnsMock::withMockedHosts([]);
    }

    #[Test]
    public function checkHostOrIpReturnsNullForUnresolvableHost(): void
    {
        DnsMock::withMockedHosts([
            'definitely-not-a-real-host.invalid' => [],
        ]);

        $result = $this->checker->checkHostOrIp('definitely-not-a-real-host.invalid');

        self::assertNull($result);
    }

    #[Test]
    public function checkHostOrIpAcceptsRawIpv4(): void
    {
        $result = $this->checker->checkHostOrIp('127.0.0.2');

        self::assertNotNull($result);
        self::assertSame('127.0.0.2', $result->ipAddress);
    }

    #[Test]
    public function checkHostOrIpResolvesDomainToIp(): void
    {
        DnsMock::withMockedHosts([
            'example.test' => [
                ['type' => 'A', 'ip' => '127.0.0.10'],
            ],
        ]);

        $result = $this->checker->checkHostOrIp('example.test');

        self::assertNotNull($result);
        self::assertSame('127.0.0.10', $result->ipAddress);
    }

    #[Test]
    public function checkHostOrIpRejectsIpv6(): void
    {
        $result = $this->checker->checkHostOrIp('::1');

        self::assertNull($result);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelBootTest extends TestCase
{
    public function testKernelBoots(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        self::assertSame('test', $kernel->getContainer()->getParameter('kernel.environment'));

        $kernel->shutdown();
    }
}

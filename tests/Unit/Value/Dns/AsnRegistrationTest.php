<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\AsnRegistration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AsnRegistrationTest extends TestCase
{
    #[Test]
    public function describesTheNetworkByNumberAndName(): void
    {
        self::assertSame('AS16509 AMAZON-02, US', new AsnRegistration(16509, 'AMAZON-02, US')->label());
    }

    #[Test]
    public function stillDescribesANetworkWhoseNameIsUnknown(): void
    {
        self::assertSame(
            'AS43037',
            new AsnRegistration(43037)->label(),
            'The number came from BGP and is worth showing on its own; suppressing it because the registry name went unanswered would lose the harder fact to keep the softer one.',
        );
    }
}

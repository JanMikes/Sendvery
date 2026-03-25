<?php

declare(strict_types=1);

namespace App\Tests\Unit\FormData;

use App\FormData\AddDomainData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddDomainDataTest extends TestCase
{
    #[Test]
    public function itHasDefaultValues(): void
    {
        $data = new AddDomainData();

        self::assertSame('', $data->domainName);
    }

    #[Test]
    public function itCanSetDomainName(): void
    {
        $data = new AddDomainData();
        $data->domainName = 'example.com';

        self::assertSame('example.com', $data->domainName);
    }
}

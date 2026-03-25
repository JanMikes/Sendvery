<?php

declare(strict_types=1);

namespace App\Tests\Unit\FormData;

use App\FormData\AddMailboxData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddMailboxDataTest extends TestCase
{
    #[Test]
    public function it_has_default_values(): void
    {
        $data = new AddMailboxData();

        self::assertSame('', $data->host);
        self::assertSame(993, $data->port);
        self::assertSame('', $data->username);
        self::assertSame('', $data->password);
        self::assertSame('ssl', $data->encryption);
        self::assertSame('imap_user', $data->type);
    }
}

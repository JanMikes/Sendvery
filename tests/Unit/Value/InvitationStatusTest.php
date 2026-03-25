<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\InvitationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvitationStatusTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('pending', InvitationStatus::Pending->value);
        self::assertSame('accepted', InvitationStatus::Accepted->value);
        self::assertSame('expired', InvitationStatus::Expired->value);
        self::assertCount(3, InvitationStatus::cases());
    }
}

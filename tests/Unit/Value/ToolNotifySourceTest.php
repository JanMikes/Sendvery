<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\ToolNotifySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolNotifySourceTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('spf-result', ToolNotifySource::Spf->value);
        self::assertSame('dkim-result', ToolNotifySource::Dkim->value);
        self::assertSame('dmarc-result', ToolNotifySource::Dmarc->value);
        self::assertSame('mx-result', ToolNotifySource::Mx->value);
        self::assertSame('email-auth-result', ToolNotifySource::EmailAuth->value);
        self::assertSame('blacklist-result', ToolNotifySource::Blacklist->value);
        self::assertSame('domain-health-result', ToolNotifySource::DomainHealth->value);
        self::assertCount(7, ToolNotifySource::cases());
    }

    #[Test]
    public function tryFromUnknownReturnsNull(): void
    {
        self::assertNull(ToolNotifySource::tryFrom('not-a-real-source'));
    }
}

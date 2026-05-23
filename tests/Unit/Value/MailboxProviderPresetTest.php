<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\MailboxEncryption;
use App\Value\MailboxProviderPreset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MailboxProviderPresetTest extends TestCase
{
    #[Test]
    public function casesReturnsSixPresets(): void
    {
        self::assertCount(6, MailboxProviderPreset::cases());
    }

    #[Test]
    public function gmailPresetHasExpectedValues(): void
    {
        $gmail = MailboxProviderPreset::find('gmail');
        self::assertNotNull($gmail);
        self::assertSame('Gmail', $gmail->label);
        self::assertSame('imap.gmail.com', $gmail->host);
        self::assertSame(993, $gmail->port);
        self::assertSame(MailboxEncryption::Ssl, $gmail->encryption);
        self::assertTrue($gmail->requiresAppPassword);
    }

    #[Test]
    public function outlookPresetRequiresAppPassword(): void
    {
        $outlook = MailboxProviderPreset::find('outlook');
        self::assertNotNull($outlook);
        self::assertTrue($outlook->requiresAppPassword);
        self::assertSame('outlook.office365.com', $outlook->host);
    }

    #[Test]
    public function customPresetHasEmptyHost(): void
    {
        $custom = MailboxProviderPreset::find('custom');
        self::assertNotNull($custom);
        self::assertSame('', $custom->host);
        self::assertFalse($custom->requiresAppPassword);
    }

    #[Test]
    public function findReturnsNullForUnknownKey(): void
    {
        self::assertNull(MailboxProviderPreset::find('definitely-not-real'));
    }

    #[Test]
    public function findReturnsPresetForKnownKey(): void
    {
        self::assertNotNull(MailboxProviderPreset::find('seznam'));
    }

    #[Test]
    public function presetsJsonContainsAllKeys(): void
    {
        $decoded = json_decode(MailboxProviderPreset::presetsJson(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('gmail', $decoded);
        self::assertArrayHasKey('outlook', $decoded);
        self::assertArrayHasKey('fastmail', $decoded);
        self::assertArrayHasKey('yahoo', $decoded);
        self::assertArrayHasKey('seznam', $decoded);
        self::assertArrayHasKey('custom', $decoded);

        self::assertSame('imap.gmail.com', $decoded['gmail']['host']);
        self::assertSame(993, $decoded['gmail']['port']);
        self::assertSame('ssl', $decoded['gmail']['encryption']);
        self::assertTrue($decoded['gmail']['requiresAppPassword']);
    }
}

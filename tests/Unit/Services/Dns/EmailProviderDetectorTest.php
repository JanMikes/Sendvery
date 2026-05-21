<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\EmailProviderDetector;
use App\Services\Dns\FakeDns;
use App\Services\OrganizationMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailProviderDetectorTest extends TestCase
{
    #[Test]
    public function detectsGoogleFromMx(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'aspmx.l.google.com', 1);

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        self::assertSame(['Google'], $detector->detect('example.com'));
    }

    #[Test]
    public function detectsMicrosoftFromMx(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'example-com.mail.protection.outlook.com');

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        self::assertSame(['Microsoft'], $detector->detect('example.com'));
    }

    #[Test]
    public function detectsSeznamFromMx(): void
    {
        $dns = (new FakeDns())
            ->withMx('myspeedpuzzling.com', '16419979780475ef.mx2.emailprofi.seznam.cz', 10)
            ->withMx('myspeedpuzzling.com', '16419979780475ef.mx1.emailprofi.seznam.cz', 20);

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        self::assertSame(['Seznam'], $detector->detect('myspeedpuzzling.com'));
    }

    #[Test]
    public function detectsSecondaryProviderFromSpfIncludes(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'aspmx.l.google.com')
            ->withTxt('example.com', 'v=spf1 include:_spf.google.com include:sendgrid.net ~all');

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        $providers = $detector->detect('example.com');
        self::assertContains('Google', $providers);
        self::assertContains('SendGrid', $providers);
    }

    #[Test]
    public function ignoresNonSpfTxtRecords(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'aspmx.l.google.com')
            ->withTxt('example.com', 'google-site-verification=abc123')
            ->withTxt('example.com', 'v=spf1 include:_spf.google.com ~all');

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        self::assertSame(['Google'], $detector->detect('example.com'));
    }

    #[Test]
    public function returnsEmptyWhenNoMxOrSpf(): void
    {
        $dns = new FakeDns();

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        self::assertSame([], $detector->detect('example.com'));
    }

    #[Test]
    public function survivesDnsErrors(): void
    {
        $dns = (new FakeDns())
            ->throwOn('example.com', 'MX')
            ->throwOn('example.com', 'TXT');

        $detector = new EmailProviderDetector($dns, new OrganizationMapper());

        self::assertSame([], $detector->detect('example.com'));
    }
}

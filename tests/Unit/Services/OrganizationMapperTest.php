<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\OrganizationMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrganizationMapperTest extends TestCase
{
    private OrganizationMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrganizationMapper();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function knownHostnameProvider(): array
    {
        return [
            'google exact' => ['google.com', 'Google'],
            'google subdomain' => ['mail-yw1-f169.google.com', 'Google'],
            'sendgrid' => ['o123.mail.sendgrid.net', 'SendGrid'],
            'amazon ses' => ['a1-23.smtp-out.amazonses.com', 'Amazon SES'],
            'mailchimp' => ['mail123.mandrillapp.com', 'Mailchimp'],
            'microsoft' => ['mail-eopbgr100045.outbound.protection.outlook.com', 'Microsoft'],
            'mailgun' => ['bounce.mailgun.org', 'Mailgun'],
            'postmark' => ['pm-bounces-1234.postmarkapp.com', 'Postmark'],
            'hubspot' => ['email123.hubspotemail.net', 'HubSpot'],
            'seznam' => ['mail.seznam.cz', 'Seznam'],
            'brevo' => ['smtp-relay.brevo.com', 'Brevo'],
        ];
    }

    #[Test]
    #[DataProvider('knownHostnameProvider')]
    public function resolvesKnownOrganizations(string $hostname, string $expected): void
    {
        self::assertSame($expected, $this->mapper->resolve($hostname));
    }

    #[Test]
    public function returnsNullForUnknownHostname(): void
    {
        self::assertNull($this->mapper->resolve('mail.unknown-company.xyz'));
    }

    #[Test]
    public function handlesTrailingDot(): void
    {
        self::assertSame('Google', $this->mapper->resolve('mail.google.com.'));
    }

    #[Test]
    public function caseInsensitive(): void
    {
        self::assertSame('Google', $this->mapper->resolve('MAIL.GOOGLE.COM'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\TopSenderForDomainResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TopSenderForDomainResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRowAuthorizedSender(): void
    {
        $row = [
            'group_key' => 'Mailchimp',
            'display_label' => 'Mailchimp',
            'total_messages' => '1000',
            'dkim_pass_count' => '920',
            'spf_pass_count' => '950',
            'known_sender_id' => '550e8400-e29b-41d4-a716-446655440000',
            'sender_is_authorized' => '1',
        ];

        $result = TopSenderForDomainResult::fromDatabaseRow($row);

        self::assertSame('Mailchimp', $result->groupKey);
        self::assertSame('Mailchimp', $result->displayLabel);
        self::assertSame(1000, $result->totalMessages);
        self::assertSame(920, $result->dkimPassCount);
        self::assertSame(92.0, $result->dkimPassRate);
        self::assertSame(950, $result->spfPassCount);
        self::assertSame(95.0, $result->spfPassRate);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $result->knownSenderId);
        self::assertTrue($result->senderIsAuthorized);
    }

    #[Test]
    public function fromDatabaseRowUnknownSender(): void
    {
        $row = [
            'group_key' => '1.2.3.4',
            'display_label' => '1.2.3.4',
            'total_messages' => 100,
            'dkim_pass_count' => 0,
            'spf_pass_count' => 0,
            'known_sender_id' => null,
            'sender_is_authorized' => null,
        ];

        $result = TopSenderForDomainResult::fromDatabaseRow($row);

        self::assertSame(0.0, $result->dkimPassRate);
        self::assertSame(0.0, $result->spfPassRate);
        self::assertNull($result->knownSenderId);
        self::assertNull($result->senderIsAuthorized);
    }

    #[Test]
    public function fromDatabaseRowZeroMessagesYieldsZeroPassRate(): void
    {
        $row = [
            'group_key' => 'tiny',
            'display_label' => 'tiny',
            'total_messages' => 0,
            'dkim_pass_count' => 0,
            'spf_pass_count' => 0,
            'known_sender_id' => null,
            'sender_is_authorized' => null,
        ];

        $result = TopSenderForDomainResult::fromDatabaseRow($row);

        self::assertSame(0.0, $result->dkimPassRate);
        self::assertSame(0.0, $result->spfPassRate);
    }

    #[Test]
    public function fromDatabaseRowAcceptsBooleanAuthorized(): void
    {
        $row = [
            'group_key' => 'Google',
            'display_label' => 'Google',
            'total_messages' => 10,
            'dkim_pass_count' => 5,
            'spf_pass_count' => 5,
            'known_sender_id' => '550e8400-e29b-41d4-a716-446655440000',
            'sender_is_authorized' => false,
        ];

        $result = TopSenderForDomainResult::fromDatabaseRow($row);

        self::assertFalse($result->senderIsAuthorized);
    }
}

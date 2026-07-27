<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\TopSenderForDomainResult;
use App\Value\SenderReviewState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TopSenderForDomainResultTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{group_key: string, display_label: string, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, known_sender_id: string|null, sender_is_authorized: int|string|bool|null, known_sender_count: int|string, needs_review_sender_count: int|string, authorized_sender_count: int|string}
     */
    private static function row(array $overrides = []): array
    {
        /** @var array{group_key: string, display_label: string, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, known_sender_id: string|null, sender_is_authorized: int|string|bool|null, known_sender_count: int|string, needs_review_sender_count: int|string, authorized_sender_count: int|string} $row */
        $row = array_merge([
            'group_key' => 'Mailchimp',
            'display_label' => 'Mailchimp',
            'total_messages' => '1000',
            'dkim_pass_count' => '920',
            'spf_pass_count' => '950',
            'known_sender_id' => '550e8400-e29b-41d4-a716-446655440000',
            'sender_is_authorized' => '1',
            'known_sender_count' => '1',
            'needs_review_sender_count' => '0',
            'authorized_sender_count' => '1',
        ], $overrides);

        return $row;
    }

    #[Test]
    public function fromDatabaseRowAuthorizedSender(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row());

        self::assertSame('Mailchimp', $result->groupKey);
        self::assertSame('Mailchimp', $result->displayLabel);
        self::assertSame(1000, $result->totalMessages);
        self::assertSame(920, $result->dkimPassCount);
        self::assertSame(92.0, $result->dkimPassRate);
        self::assertSame(950, $result->spfPassCount);
        self::assertSame(95.0, $result->spfPassRate);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $result->knownSenderId);
        self::assertTrue($result->senderIsAuthorized);
        self::assertSame(SenderReviewState::Authorized, $result->reviewState);
    }

    #[Test]
    public function fromDatabaseRowUnknownSender(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'group_key' => '1.2.3.4',
            'display_label' => '1.2.3.4',
            'total_messages' => 100,
            'dkim_pass_count' => 0,
            'spf_pass_count' => 0,
            'known_sender_id' => null,
            'sender_is_authorized' => null,
            'known_sender_count' => 0,
            'needs_review_sender_count' => 0,
            'authorized_sender_count' => 0,
        ]));

        self::assertSame(0.0, $result->dkimPassRate);
        self::assertSame(0.0, $result->spfPassRate);
        self::assertNull($result->knownSenderId);
        self::assertNull($result->senderIsAuthorized);
    }

    #[Test]
    public function fromDatabaseRowZeroMessagesYieldsZeroPassRate(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'group_key' => 'tiny',
            'display_label' => 'tiny',
            'total_messages' => 0,
            'dkim_pass_count' => 0,
            'spf_pass_count' => 0,
        ]));

        self::assertSame(0.0, $result->dkimPassRate);
        self::assertSame(0.0, $result->spfPassRate);
    }

    #[Test]
    public function fromDatabaseRowAcceptsBooleanAuthorized(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'group_key' => 'Google',
            'display_label' => 'Google',
            'total_messages' => 10,
            'dkim_pass_count' => 5,
            'spf_pass_count' => 5,
            'sender_is_authorized' => false,
            'authorized_sender_count' => 0,
            'needs_review_sender_count' => 1,
        ]));

        self::assertFalse($result->senderIsAuthorized);
    }

    /**
     * A group with no `known_sender` row behind it has nothing for the user to
     * decide, so it must not render as an amber "needs your attention" badge —
     * the old two-way `{% if senderIsAuthorized %}` did exactly that.
     */
    #[Test]
    public function aGroupWithNoInventoryRowHasNoReviewStateAtAll(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'known_sender_id' => null,
            'sender_is_authorized' => null,
            'known_sender_count' => 0,
            'needs_review_sender_count' => 0,
            'authorized_sender_count' => 0,
        ]));

        self::assertNull($result->reviewState);
    }

    #[Test]
    public function aGroupNobodyHasDecidedAboutAsksForReview(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'known_sender_count' => 1,
            'needs_review_sender_count' => 1,
            'authorized_sender_count' => 0,
        ]));

        self::assertSame(SenderReviewState::NeedsReview, $result->reviewState);
    }

    /**
     * Senders are grouped by organisation, so one row can cover several IPs in
     * different states. An IP the user explicitly rejected that is still
     * delivering mail is the most urgent thing in the group, so it wins the
     * badge even when a sibling IP is authorized.
     */
    #[Test]
    public function aGroupContainingARejectedSenderShowsAsNotAuthorized(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'known_sender_count' => 3,
            'needs_review_sender_count' => 1,
            'authorized_sender_count' => 1,
        ]));

        self::assertSame(SenderReviewState::NotAuthorized, $result->reviewState);
    }

    #[Test]
    public function aGroupMixingAuthorizedAndUnreviewedSendersStillAsksForReview(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'known_sender_count' => 5,
            'needs_review_sender_count' => 4,
            'authorized_sender_count' => 1,
        ]));

        self::assertSame(SenderReviewState::NeedsReview, $result->reviewState);
    }

    #[Test]
    public function aFullyAuthorizedGroupIsSettled(): void
    {
        $result = TopSenderForDomainResult::fromDatabaseRow(self::row([
            'known_sender_count' => 5,
            'needs_review_sender_count' => 0,
            'authorized_sender_count' => 5,
        ]));

        self::assertSame(SenderReviewState::Authorized, $result->reviewState);
    }
}

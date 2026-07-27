<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\ReportSenderGroupResult;
use App\Value\PolicyOverrideReasonType;
use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportSenderGroupResultTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{group_key: string, display_label: string, sender_role: string|null, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, disposition_none: int|string, disposition_quarantine: int|string, disposition_reject: int|string, source_ips: string, sender_is_authorized: int|string|null, known_sender_count: int|string, needs_review_sender_count: int|string, authorized_sender_count: int|string, policy_override_reasons: string|null}
     */
    private static function row(array $overrides = []): array
    {
        /** @var array{group_key: string, display_label: string, sender_role: string|null, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, disposition_none: int|string, disposition_quarantine: int|string, disposition_reject: int|string, source_ips: string, sender_is_authorized: int|string|null, known_sender_count: int|string, needs_review_sender_count: int|string, authorized_sender_count: int|string, policy_override_reasons: string|null} $row */
        $row = array_merge([
            'group_key' => 'cloud-sec-av.com',
            'display_label' => 'cloud-sec-av.com',
            'sender_role' => 'forwarder',
            'total_messages' => '4',
            'dkim_pass_count' => '3',
            'spf_pass_count' => '0',
            'disposition_none' => '4',
            'disposition_quarantine' => '0',
            'disposition_reject' => '0',
            'source_ips' => '{52.212.19.177,15.222.110.90,35.174.145.124}',
            'sender_is_authorized' => null,
            // No inventory rows behind this group yet, so every per-state count
            // is zero — the gateway has never been reviewed by anyone.
            'known_sender_count' => '0',
            'needs_review_sender_count' => '0',
            'authorized_sender_count' => '0',
            // The FILTERed json_agg selects NULL when no receiver annotated any
            // of the group's records — every group in production today.
            'policy_override_reasons' => null,
        ], $overrides);

        return $row;
    }

    #[Test]
    public function aGroupCarriesEveryAddressBehindTheSenderItNames(): void
    {
        $result = ReportSenderGroupResult::fromDatabaseRow(self::row());

        self::assertSame('cloud-sec-av.com', $result->displayLabel);
        self::assertSame(4, $result->totalMessages);
        self::assertSame(75.0, $result->dkimPassRate);
        self::assertSame(0.0, $result->spfPassRate);
        self::assertSame(
            ['52.212.19.177', '15.222.110.90', '35.174.145.124'],
            $result->sourceIps,
        );
        self::assertSame(SenderRole::Forwarder, $result->senderRole);
        self::assertNull($result->senderIsAuthorized);
        self::assertFalse(
            $result->forwarding->attestsForwarding,
            'A group no receiver annotated carries no attestation, whatever its cached role says.',
        );
    }

    #[Test]
    public function carriesWhatTheReceiverSaidAboutItsOwnHandlingOfTheMail(): void
    {
        $result = ReportSenderGroupResult::fromDatabaseRow(self::row([
            'policy_override_reasons' => '[[{"type":"trusted_forwarder","comment":null}]]',
        ]));

        self::assertTrue($result->forwarding->attestsForwarding);
        self::assertSame(PolicyOverrideReasonType::TrustedForwarder, $result->forwarding->attestedBy);
    }

    #[Test]
    public function aSenderNothingHasClassifiedCarriesNoRole(): void
    {
        $result = ReportSenderGroupResult::fromDatabaseRow(self::row([
            'group_key' => '198.51.100.4',
            'display_label' => '198.51.100.4',
            'sender_role' => null,
            'source_ips' => '{198.51.100.4}',
            'sender_is_authorized' => '1',
        ]));

        self::assertNull($result->senderRole);
        self::assertTrue($result->senderIsAuthorized);
    }

    /**
     * A group with no messages divides by nothing, and a reporter is entitled
     * to describe a source that carried none.
     */
    #[Test]
    public function aGroupWithNoMessagesReportsZeroRatherThanFailing(): void
    {
        $result = ReportSenderGroupResult::fromDatabaseRow(self::row([
            'total_messages' => 0,
            'dkim_pass_count' => 0,
            'spf_pass_count' => 0,
            'source_ips' => '{}',
        ]));

        self::assertSame(0.0, $result->dkimPassRate);
        self::assertSame(0.0, $result->spfPassRate);
        self::assertSame([], $result->sourceIps, 'An empty address array is a legal aggregate, not a parse failure.');
    }
}

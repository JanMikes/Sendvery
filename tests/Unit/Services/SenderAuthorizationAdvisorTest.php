<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\SenderActivity30Day;
use App\Results\SenderAdvisorResult;
use App\Results\SenderInventoryResult;
use App\Services\SenderAuthorizationAdvisor;
use App\Value\SenderAdvisorSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pure-computation coverage for {@see SenderAuthorizationAdvisor} branches
 * (TASK-092). The advisor is the only place that decides which (if any) of
 * the inline row callouts renders on `/app/domains/{id}/senders`. Locking
 * every threshold boundary here keeps the eligibility rules deterministic
 * across every "is this sender worth your attention?" surface in the app.
 */
final class SenderAuthorizationAdvisorTest extends TestCase
{
    #[Test]
    public function recommendAuthorizeWhenKnownOrgAndHighPassRateAndAboveVolume(): void
    {
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'Mailchimp', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 1247, dkimPassRate: 98.5);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::RecommendAuthorize, $result->severity);
        self::assertStringContainsString('Mailchimp', $result->reasonText);
        self::assertStringContainsString('1,247 messages', $result->reasonText);
        self::assertStringContainsString('98.5% DMARC pass', $result->reasonText);
        self::assertSame('Authorize sender', $result->primaryActionLabel);
    }

    #[Test]
    public function recommendAuthorizeTrimsTrailingZeroOnIntegerPassRate(): void
    {
        // Cosmetic: "100% pass" reads cleaner than "100.0%" in the row banner.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'Postmark', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 200, dkimPassRate: 100.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::RecommendAuthorize, $result->severity);
        self::assertStringContainsString('100% DMARC pass', $result->reasonText);
        self::assertStringNotContainsString('100.0%', $result->reasonText);
    }

    #[Test]
    public function recommendAuthorizeSuppressedWhenOrganizationUnknown(): void
    {
        // 1000 messages, 99% pass, but we can't name the source — we must NOT
        // pre-stamp "authorize" because there's nothing concrete to confirm.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: null, isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 1000, dkimPassRate: 99.0);

        $result = $advisor->advise($sender, $activity);

        self::assertNotSame(SenderAdvisorSeverity::RecommendAuthorize, $result->severity);
    }

    #[Test]
    public function recommendAuthorizeSuppressedWhenOrganizationIsEmptyString(): void
    {
        // Defensive: PostgreSQL allows empty-string columns, the advisor must
        // treat them like null and not produce a callout headlined "  has sent…".
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: '', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 1000, dkimPassRate: 99.0);

        $result = $advisor->advise($sender, $activity);

        self::assertNotSame(SenderAdvisorSeverity::RecommendAuthorize, $result->severity);
    }

    #[Test]
    public function recommendAuthorizeFiresExactlyAt50Messages(): void
    {
        // Boundary check: 50 messages and 90% pass — both at the threshold,
        // should fire. 49 / 89.99 should not (covered below).
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'Sendgrid', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 50, dkimPassRate: 90.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::RecommendAuthorize, $result->severity);
    }

    #[Test]
    public function monitorWhenJustBelowAuthorizeThresholdOnVolume(): void
    {
        // 49 messages — one below the 50 floor. Falls into Monitor (above
        // the MONITOR_MIN of 5) rather than RecommendAuthorize.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'Sendgrid', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 49, dkimPassRate: 99.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::Monitor, $result->severity);
        self::assertStringContainsString('Watching Sendgrid', $result->reasonText);
    }

    #[Test]
    public function monitorWhenJustBelowAuthorizeThresholdOnPassRate(): void
    {
        // 89.9% pass — below the 90% authorize floor but above the 50% revoke
        // ceiling, and the sender is named so revoke wouldn't fire anyway.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'Mailchimp', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 500, dkimPassRate: 89.9);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::Monitor, $result->severity);
    }

    #[Test]
    public function recommendRevokeWhenUnknownAndLowPassRateAndAboveVolume(): void
    {
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(
            sourceIp: '203.0.113.42',
            organization: null,
            isAuthorized: false,
        );
        $activity = new SenderActivity30Day(totalMessages: 75, dkimPassRate: 12.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::RecommendRevoke, $result->severity);
        self::assertStringContainsString('Unknown sender at 203.0.113.42', $result->reasonText);
        self::assertStringContainsString('75 failing messages', $result->reasonText);
        self::assertSame('Mark as revoked', $result->primaryActionLabel);
    }

    #[Test]
    public function recommendRevokeFiresExactlyAt20Messages(): void
    {
        // Boundary check: 20 messages, 49.9% — should fire.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: null, isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 20, dkimPassRate: 49.9);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::RecommendRevoke, $result->severity);
    }

    #[Test]
    public function recommendRevokeSuppressedExactlyAt50PercentPassRate(): void
    {
        // Boundary check: 50% pass rate is EXCLUSIVE (the `<` in the rule) —
        // a sender at exactly 50% is borderline, not "likely spoofing".
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: null, isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 100, dkimPassRate: 50.0);

        $result = $advisor->advise($sender, $activity);

        self::assertNotSame(SenderAdvisorSeverity::RecommendRevoke, $result->severity);
    }

    #[Test]
    public function recommendRevokeSuppressedForKnownOrganization(): void
    {
        // A known organisation with a low pass rate is more likely misconfigured
        // than malicious — the spec explicitly rejects auto-revoke for them.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'BrokenCorp', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 100, dkimPassRate: 10.0);

        $result = $advisor->advise($sender, $activity);

        self::assertNotSame(SenderAdvisorSeverity::RecommendRevoke, $result->severity);
        // Falls into Monitor — enough volume to surface, no concrete verdict.
        self::assertSame(SenderAdvisorSeverity::Monitor, $result->severity);
    }

    #[Test]
    public function monitorMentionsIpWhenOrganizationIsUnknown(): void
    {
        // Monitor copy variant: when there's no org, the watchword names the IP.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(
            sourceIp: '198.51.100.7',
            organization: null,
            isAuthorized: false,
        );
        // Just below revoke volume (20) — Monitor, not Revoke.
        $activity = new SenderActivity30Day(totalMessages: 19, dkimPassRate: 10.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::Monitor, $result->severity);
        self::assertStringContainsString('198.51.100.7', $result->reasonText);
    }

    #[Test]
    public function noneWhenSenderAuthorized(): void
    {
        // Authorized senders never get a "make a decision" callout.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'Mailchimp', isAuthorized: true);
        $activity = new SenderActivity30Day(totalMessages: 5000, dkimPassRate: 99.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::None, $result->severity);
        self::assertNull($result->primaryActionLabel);
    }

    #[Test]
    public function noneWhenBelowMonitorFloor(): void
    {
        // 4 messages — below the 5-message Monitor floor. Suppressed entirely.
        $advisor = new SenderAuthorizationAdvisor();
        $sender = $this->makeSender(organization: 'TinySender', isAuthorized: false);
        $activity = new SenderActivity30Day(totalMessages: 4, dkimPassRate: 100.0);

        $result = $advisor->advise($sender, $activity);

        self::assertSame(SenderAdvisorSeverity::None, $result->severity);
    }

    #[Test]
    public function noneFactoryProducesNoneSeverity(): void
    {
        $result = SenderAdvisorResult::none('sender-abc');

        self::assertSame('sender-abc', $result->senderId);
        self::assertSame(SenderAdvisorSeverity::None, $result->severity);
        self::assertSame('', $result->reasonText);
        self::assertNull($result->primaryActionLabel);
    }

    #[Test]
    public function activitySummaryEmptyReturnsZeroAndZeroRate(): void
    {
        $empty = SenderActivity30Day::empty();

        self::assertSame(0, $empty->totalMessages);
        self::assertSame(0.0, $empty->dkimPassRate);
    }

    #[Test]
    public function activitySummaryFromDatabaseRowComputesPassRate(): void
    {
        $row = SenderActivity30Day::fromDatabaseRow([
            'total_messages_30d' => '200',
            'dkim_pass_count_30d' => '180',
        ]);

        self::assertSame(200, $row->totalMessages);
        self::assertSame(90.0, $row->dkimPassRate);
    }

    #[Test]
    public function activitySummaryFromDatabaseRowHandlesZeroVolume(): void
    {
        // Edge case: the SQL groups by IP, so a zero-message row would not
        // normally show up, but the divide-by-zero defence is worth a test.
        $row = SenderActivity30Day::fromDatabaseRow([
            'total_messages_30d' => '0',
            'dkim_pass_count_30d' => '0',
        ]);

        self::assertSame(0, $row->totalMessages);
        self::assertSame(0.0, $row->dkimPassRate);
    }

    private function makeSender(
        ?string $organization = null,
        bool $isAuthorized = false,
        string $sourceIp = '203.0.113.10',
    ): SenderInventoryResult {
        return new SenderInventoryResult(
            id: Uuid::uuid7()->toString(),
            sourceIp: $sourceIp,
            hostname: null,
            organization: $organization,
            label: null,
            isAuthorized: $isAuthorized,
            firstSeenAt: '2026-04-01 09:00:00',
            lastSeenAt: '2026-05-24 09:00:00',
            totalMessages: 999,
            passRate: 99.0,
            updatedAt: null,
            notes: null,
            updatedByUserEmail: null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertSeverity;
use App\Value\SenderRole;
use App\Value\WeeklyDigestAlertItem;
use App\Value\WeeklyDigestBrokenDnsItem;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
use App\Value\WeeklyDigestNewSender;
use App\Value\WeeklyDigestSection;
use App\Value\WeeklyDigestSections;
use App\Value\WeeklyDigestSenderReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One answer to "is this section in this week's digest?", shared by the HTML
 * template and the plain-text renderer. Two copies of these conditions is how
 * the two alternatives came to disagree in the first place.
 */
final class WeeklyDigestSectionsTest extends TestCase
{
    #[Test]
    public function aWeekWithNothingToReportStillCarriesTheHeadlineNumbers(): void
    {
        $sections = WeeklyDigestSections::of($this->emptyDigest(), hasAiSummary: false);

        self::assertTrue(
            $sections->has(WeeklyDigestSection::Summary),
            'A digest with no numbers in it is not a digest — the summary is unconditional.',
        );

        foreach (WeeklyDigestSection::cases() as $section) {
            if (WeeklyDigestSection::Summary === $section) {
                continue;
            }

            self::assertFalse(
                $sections->has($section),
                sprintf('Nothing happened this week, so "%s" has nothing to say.', $section->value),
            );
        }
    }

    #[Test]
    public function eachSectionAppearsExactlyWhenItHasSomethingToSay(): void
    {
        $digest = new WeeklyDigestData(
            teamName: 'My Team',
            periodStart: new \DateTimeImmutable('2026-03-18'),
            periodEnd: new \DateTimeImmutable('2026-03-25'),
            domains: [new WeeklyDigestDomainData(
                domainName: 'example.com',
                totalMessages: 10,
                passedMessages: 10,
                passRate: 100.0,
                passRateDelta: null,
                newSenders: [new WeeklyDigestNewSender('google.com', SenderRole::Esp, 10, 10)],
                domainId: '00000000-0000-0000-0000-000000000001',
                senderReview: new WeeklyDigestSenderReview(2, 40, ['google.com'], 2),
            )],
            totalDomains: 1,
            totalMessages: 10,
            totalPassedMessages: 10,
            alertsCount: 1,
            attentionAlertGroups: 1,
            attentionAlerts: [new WeeklyDigestAlertItem('SPF record missing', AlertSeverity::Critical, 'example.com', 1)],
            resolvedAlertsCount: 1,
            dnsChangesCount: 1,
            currentlyBrokenDns: [new WeeklyDigestBrokenDnsItem(
                domainName: 'example.com',
                checkType: 'DMARC',
                checkedAt: new \DateTimeImmutable('2026-03-24 07:15:00'),
                issueMessages: ['No DMARC record found.'],
            )],
        );

        $sections = WeeklyDigestSections::of($digest, hasAiSummary: true);

        foreach (WeeklyDigestSection::cases() as $section) {
            self::assertTrue(
                $sections->has($section),
                sprintf('"%s" has content this week and must be rendered.', $section->value),
            );
        }
    }

    #[Test]
    public function anAiSummaryIsTheOnlySectionThatDependsOnSomethingOutsideTheDigest(): void
    {
        // Plan-gated and provider-dependent: the same week's numbers produce a
        // digest with or without narration, which is why presence is passed in
        // rather than derived.
        $digest = $this->emptyDigest();

        self::assertTrue(WeeklyDigestSections::of($digest, hasAiSummary: true)->has(WeeklyDigestSection::AiSummary));
        self::assertFalse(WeeklyDigestSections::of($digest, hasAiSummary: false)->has(WeeklyDigestSection::AiSummary));
    }

    #[Test]
    public function aMisspelledSectionNameInATemplateIsLoudRatherThanQuietlyFalse(): void
    {
        // Twig asks by name. If a typo returned false the section would simply
        // vanish from the email and every test would still pass.
        $sections = WeeklyDigestSections::of($this->emptyDigest(), hasAiSummary: false);

        $this->expectException(\ValueError::class);

        $sections->has('sender_reviews');
    }

    private function emptyDigest(): WeeklyDigestData
    {
        return new WeeklyDigestData(
            teamName: 'My Team',
            periodStart: new \DateTimeImmutable('2026-03-18'),
            periodEnd: new \DateTimeImmutable('2026-03-25'),
            domains: [],
            totalDomains: 0,
            totalMessages: 0,
            totalPassedMessages: 0,
            alertsCount: 0,
            attentionAlertGroups: 0,
            attentionAlerts: [],
            resolvedAlertsCount: 0,
            dnsChangesCount: 0,
        );
    }
}

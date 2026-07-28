<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Digest;

use App\Services\Digest\WeeklyDigestPlainTextRenderer;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
use App\Value\WeeklyDigestSections;
use App\Value\WeeklyDigestSenderReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Percentages in the two alternatives of one email must round the same way.
 *
 * The HTML formats every percentage with Twig's `number_format`, which rounds a
 * half away from zero. The plain text used `sprintf('%.1f')`, which does not:
 * PHP prints 83.25 as "83.2" there and "83.3" in the template. Both numbers
 * come from the same float, so the customer is reading one measurement
 * described two ways — small, but it is the same class of defect as the
 * attention count, and it is free to remove.
 */
final class WeeklyDigestPlainTextRendererTest extends TestCase
{
    #[Test]
    public function aPercentageOnARoundingBoundaryReadsTheSameAsItDoesInTheHtml(): void
    {
        // 3330 of 4000 messages is exactly 83.25%. Guard the premise: if the
        // two formatters ever agree on this value, the test proves nothing and
        // should be given a value where they still differ.
        $onTheBoundary = 3330 / 4000 * 100;
        self::assertNotSame(
            number_format($onTheBoundary, 1),
            sprintf('%.1f', $onTheBoundary),
            'This value must be one the two formatters round differently, or nothing is being tested.',
        );

        $text = $this->render($onTheBoundary, delta: 33.25);

        // What Twig's number_format(…, 1) produces for the same float, which is
        // therefore what the HTML alternative of this very email says.
        self::assertStringContainsString('Overall pass rate: '.number_format($onTheBoundary, 1).'%', $text);
        self::assertStringContainsString('Pass rate: '.number_format($onTheBoundary, 1).'%', $text);
        self::assertStringContainsString('Trend: +'.number_format(33.25, 1).'%', $text);
    }

    #[Test]
    public function aDomainThatReportedNothingIsStillNotGivenANumber(): void
    {
        $text = $this->render(null, delta: null);

        self::assertStringContainsString('Overall pass rate: no reports yet', $text);
        self::assertStringContainsString('Pass rate: waiting for first report', $text);
        self::assertStringNotContainsString('Trend:', $text, 'A trend needs real numbers on both sides.');
    }

    private function render(?float $passRate, ?float $delta): string
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/senders');

        $messages = null === $passRate ? 0 : 4000;
        $passed = null === $passRate ? 0 : 3330;

        $digest = new WeeklyDigestData(
            teamName: 'My Team',
            periodStart: new \DateTimeImmutable('2026-03-18'),
            periodEnd: new \DateTimeImmutable('2026-03-25'),
            domains: [new WeeklyDigestDomainData(
                domainName: 'example.com',
                totalMessages: $messages,
                passedMessages: $passed,
                passRate: $passRate,
                passRateDelta: $delta,
                newSenders: [],
                domainId: '00000000-0000-0000-0000-000000000001',
                senderReview: WeeklyDigestSenderReview::none(),
            )],
            totalDomains: 1,
            totalMessages: $messages,
            totalPassedMessages: $passed,
            alertsCount: 0,
            attentionAlertGroups: 0,
            attentionAlerts: [],
            resolvedAlertsCount: 0,
            dnsChangesCount: 0,
        );

        return (new WeeklyDigestPlainTextRenderer($urlGenerator))->render(
            digest: $digest,
            sections: WeeklyDigestSections::of($digest, hasAiSummary: false),
            dashboardUrl: 'https://example.test/app',
            alertsUrl: 'https://example.test/app/alerts',
            dateRange: 'Mar 18 — Mar 25, 2026',
            aiSummary: null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainAttentionResult;
use App\Value\DomainAttentionReason;
use App\Value\DomainHealthFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tone an attention row paints itself in. It has to match the domain card
 * on `/app/domains` and the banner on the domain's own page for the same
 * verdict, otherwise the same domain changes colour as the user navigates.
 */
final class DomainAttentionResultTest extends TestCase
{
    /**
     * @return iterable<string, array{DomainHealthFilter, string}>
     */
    public static function severityToneCases(): iterable
    {
        yield 'an unverified domain is the error tone' => [DomainHealthFilter::Unverified, 'error'];
        yield 'a domain needing attention is the warning tone' => [DomainHealthFilter::Attention, 'warning'];
        yield 'a healthy domain is the success tone' => [DomainHealthFilter::Healthy, 'success'];
    }

    #[Test]
    #[DataProvider('severityToneCases')]
    public function severityMapsOntoTheSameToneTheDomainCardsUse(DomainHealthFilter $severity, string $expectedTone): void
    {
        self::assertSame($expectedTone, $this->attentionRow($severity, checkInProgress: false)->severityTone());
    }

    #[Test]
    public function aDomainWhoseFirstCheckIsStillRunningIsNeutralInfo(): void
    {
        // Even though the verdict is Unverified, we have not looked yet — painting
        // a brand-new domain red accuses it of a failure we have not observed.
        $result = $this->attentionRow(DomainHealthFilter::Unverified, checkInProgress: true);

        self::assertSame('info', $result->severityTone());
    }

    private function attentionRow(DomainHealthFilter $severity, bool $checkInProgress): DomainAttentionResult
    {
        return new DomainAttentionResult(
            domainId: '019fa000-0000-7000-8000-000000000001',
            domainName: 'acme.example',
            severity: $severity,
            headline: 'Setup incomplete — DMARC record not yet published',
            reasons: [new DomainAttentionReason('DMARC', 'DMARC TXT record not detected', 'error')],
            ctaLabel: 'Set up DMARC',
            ctaRoute: 'dashboard_domain_health',
            ctaRouteParams: ['id' => '019fa000-0000-7000-8000-000000000001', '_fragment' => 'health-dmarc'],
            passRate: null,
            awaitingFirstReport: true,
            checkInProgress: $checkInProgress,
        );
    }
}

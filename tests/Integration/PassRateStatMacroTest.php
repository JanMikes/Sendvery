<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/**
 * The shared no-data pass-rate presentation. Every surface that prints a pass
 * rate goes through these macros, so the "0% means every message failed / no
 * number means we have nothing to grade" distinction is written exactly once.
 */
final class PassRateStatMacroTest extends IntegrationTestCase
{
    #[Test]
    public function aMissingPassRateIsPresentedAsWaitingForTheFirstReport(): void
    {
        $html = $this->renderStat(null, awaitingFirstReport: true);

        self::assertStringContainsString('Waiting for first report', $html);
        self::assertStringNotContainsString('%', $html);
    }

    #[Test]
    public function aDomainThatReportedBeforeButNotThisPeriodSaysSoInsteadOfWaiting(): void
    {
        // Telling a long-established domain it is "waiting for its first report"
        // would read as broken setup rather than a quiet week.
        $html = $this->renderStat(null, awaitingFirstReport: false);

        self::assertStringContainsString('No reports in this period', $html);
        self::assertStringNotContainsString('Waiting for first report', $html);
    }

    #[Test]
    public function aMeasuredPassRateIsPrintedWithOneDecimalAndItsCaption(): void
    {
        $html = $this->renderStat(94.25, awaitingFirstReport: false);

        self::assertStringContainsString('94.3%', $html);
        self::assertStringContainsString('pass rate', $html);
    }

    #[Test]
    public function theCompactVariantPrintsOneFigureWithoutACaption(): void
    {
        $html = $this->renderStat(94.25, awaitingFirstReport: false, size: 'sm');

        self::assertStringContainsString('94.3%', $html);
        self::assertStringNotContainsString('pass rate', $html);
    }

    #[Test]
    public function theCompactVariantAlsoCarriesTheWaitingWording(): void
    {
        $html = $this->renderStat(null, awaitingFirstReport: true, size: 'sm');

        self::assertStringContainsString('Waiting for first report', $html);
    }

    /**
     * The tone mapping is a business rule — 90 is the healthy threshold used by
     * the classifier — so the semantic daisyUI tokens are worth asserting.
     *
     * @return iterable<string, array{float|null, string}>
     */
    public static function toneCases(): iterable
    {
        yield 'no data is neutral, not a warning' => [null, 'text-base-content/40'];
        yield 'at the healthy threshold' => [90.0, 'text-success'];
        yield 'just below the healthy threshold' => [89.9, 'text-warning'];
        yield 'well below' => [69.9, 'text-error'];
        yield 'every message failed' => [0.0, 'text-error'];
    }

    #[Test]
    #[DataProvider('toneCases')]
    public function passRateToneFollowsTheHealthThresholds(?float $passRate, string $expectedClass): void
    {
        $twig = $this->getService(Environment::class);

        $html = $twig->createTemplate(
            "{% import 'components/_severity_glyph.html.twig' as severity %}{{ severity.pass_rate_class(passRate)|trim }}",
        )->render(['passRate' => $passRate]);

        self::assertSame($expectedClass, $html);
    }

    #[Test]
    public function aMissingPassRateRendersTheSamePlaceholderAsAnEmptySparkline(): void
    {
        $twig = $this->getService(Environment::class);

        $html = $twig->createTemplate(
            "{% import 'components/_severity_glyph.html.twig' as severity %}{{ severity.pass_rate_value(passRate)|trim }}",
        )->render(['passRate' => null]);

        self::assertSame('—', $html);
    }

    private function renderStat(?float $passRate, bool $awaitingFirstReport, string $size = 'lg'): string
    {
        $twig = $this->getService(Environment::class);

        return $twig->createTemplate(
            "{% import 'components/_severity_glyph.html.twig' as severity %}"
            .'{{ severity.pass_rate_stat(passRate, awaiting, size) }}',
        )->render([
            'passRate' => $passRate,
            'awaiting' => $awaitingFirstReport,
            'size' => $size,
        ]);
    }
}

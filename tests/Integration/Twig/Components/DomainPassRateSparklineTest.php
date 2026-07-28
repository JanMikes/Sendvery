<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/**
 * The 30-day pass-rate sparkline beside each Domain Health row.
 *
 * A bucket carries `null` when no message was observed in it. That is NOT the
 * same fact as "every message in that window failed", and drawing it at the
 * bottom of the box says the second thing. The component's own header already
 * promises that its "—" placeholder and the pass-rate figure beside it "always
 * tell the same story"; before this, an all-empty series arrived as ten zeroes,
 * cleared the `count >= 2` gate, and drew a flat line along the floor next to a
 * figure that honestly said "Waiting for first report".
 */
final class DomainPassRateSparklineTest extends IntegrationTestCase
{
    #[Test]
    public function aSeriesWithNothingMeasuredRendersThePlaceholderRatherThanAFlatLineOnTheFloor(): void
    {
        $html = $this->render([null, null, null, null, null, null, null, null, null, null]);

        self::assertStringContainsString('—', $html);
        self::assertStringNotContainsString('<polyline', $html);
        self::assertStringNotContainsString('<circle', $html);
    }

    #[Test]
    public function anUnmeasuredWindowBreaksTheLineInTwoRatherThanBridgingIt(): void
    {
        // Bridging the gap would draw a straight line across days we never
        // measured — inventing data. Collapsing to "—" would throw away the
        // days we did measure. Two segments is the only honest rendering.
        $html = $this->render([100.0, 100.0, null, null, 50.0, 50.0]);

        self::assertSame(2, substr_count($html, '<polyline'), 'One polyline per run of consecutive measured buckets.');
        self::assertStringNotContainsString('—', $html);
    }

    #[Test]
    public function aLoneMeasuredWindowBetweenTwoGapsRendersAsADotAtItsOwnPosition(): void
    {
        // A run of one has no line to draw, but it is a real measurement and
        // must not vanish. It renders as a dot at that bucket's own x/y — not
        // at the centre of the box, which would misplace it in time.
        $html = $this->render([null, null, 100.0, null, null]);

        self::assertStringContainsString('<circle', $html);
        self::assertStringNotContainsString('<polyline', $html);
        self::assertStringNotContainsString('—', $html);
        // 5 buckets over a 78px drawable width → step 19.5px, so bucket 2 sits
        // at x = 1 + 39 = 40. A centred dot would land at 40 too, so pin the y
        // instead: a 100% rate rides the top of the box, not the middle.
        self::assertStringContainsString('cy="1.00"', $html, 'A 100% bucket belongs at the top of the box.');
    }

    #[Test]
    public function measuredBucketsStillPlotWhenNothingIsMissing(): void
    {
        // The guard against over-correcting: a fully measured series must still
        // draw exactly one continuous line.
        $html = $this->render([10.0, 20.0, 30.0]);

        self::assertSame(1, substr_count($html, '<polyline'));
        self::assertStringNotContainsString('—', $html);
    }

    #[Test]
    public function anEmptySeriesStillRendersThePlaceholder(): void
    {
        self::assertStringContainsString('—', $this->render([]));
    }

    /** @param list<float|null> $points */
    private function render(array $points): string
    {
        $twig = $this->getService(Environment::class);

        return $twig->createTemplate('<twig:DomainPassRateSparkline :points="points" />')
            ->render(['points' => $points]);
    }
}

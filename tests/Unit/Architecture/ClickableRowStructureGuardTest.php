<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Tests\TestSupport\ProjectSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Repo-wide guards for "click a row, open that row's record".
 *
 * THE DEFECT THESE EXIST FOR: every row of the DMARC reports table opened the
 * SAME report. The hrefs were correct — the bug was CSS. Each row carried
 * `<a class="absolute inset-0 z-10">` inside a `<td>` and relied on
 * `position: relative` on the `<tr>` to be that anchor's containing block. CSS
 * 2.1 §9.3.1 leaves the effect of `position` on `display: table-row`
 * EXPLICITLY UNDEFINED, and daisyUI 5's `.table` is itself `position: relative`
 * — so in a browser that ignores positioning on `<tr>`, every row's overlay
 * resolved its containing block to the whole `<table>`, all overlays stacked at
 * one z-index, and the last one painted swallowed every click. The user clicked
 * row 1 and got row 12.
 *
 * Two things made it expensive: the pattern had been copy-pasted into seven
 * dashboard tables, and its documented contract ("the `<a>` is `absolute
 * inset-0` inside the FIRST `<td>`") was silently broken later when a commit
 * inserted a leading severity-glyph `<td>` in front of it. Nothing in the test
 * suite could notice either. These guards are that missing notice.
 *
 * They scan template source rather than crawling pages: the file set is
 * discovered from disk, so a table added to a new template tomorrow is covered
 * without anyone remembering to extend a list of URLs.
 *
 * See {@see ProjectSource} for why asserting the absence of these class
 * combinations does not violate CLAUDE.md's "never assert Tailwind classes"
 * rule, and {@see \App\Tests\Integration\Architecture\RenderedRowDestinationsGuardTest}
 * for the complementary rendered check.
 *
 * Every guard below has a paired `…GuardItselfFailsOn…` test that feeds the
 * detector a synthetic violation. That is deliberate: a guard nobody has ever
 * watched fail is indistinguishable from a guard that cannot fail, and the
 * second kind is worse than none because it manufactures confidence.
 */
final class ClickableRowStructureGuardTest extends TestCase
{
    private const string ROW_LINK_TARGET = 'data-row-link-target="link"';

    #[Test]
    public function noTableRowNavigatesThroughAStretchedOverlayAnchor(): void
    {
        $offenders = $this->scan(self::overlayAnchorsInsideTables(...));

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                An absolutely-positioned anchor inside a table row is the bug that made
                every row of a table open the same record.

                The overlay's containing block is whatever ancestor is positioned. A `<tr>`
                is `display: table-row`, and CSS 2.1 §9.3.1 leaves the effect of `position`
                on a table-row UNDEFINED — so the browser may skip it and resolve against
                daisyUI's `.table`, which IS `position: relative`. Every row's overlay then
                covers the entire table, they stack at the same z-index, and the last one in
                DOM order eats every click.

                Use one real, visible `<a href>` in a cell plus the `row-link` Stimulus
                controller (assets/controllers/row_link_controller.js). That keeps the row
                clickable, keyboard-reachable, middle-clickable and screen-reader-correct
                without depending on undefined CSS.

                Offending anchors:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function noTableRowIsPositionedRelative(): void
    {
        $offenders = $this->scan(self::positionedTableRows(...));

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                `position: relative` on a `<tr>` does nothing reliable. CSS 2.1 §9.3.1
                leaves positioning of `display: table-row` undefined, so whether the row
                becomes a containing block is a per-browser accident. Any layout that
                depends on it — an overlay, an absolutely-positioned badge, a tooltip
                anchor — works in the browser it was built in and silently mis-resolves
                everywhere else, against daisyUI's relatively-positioned `.table`.

                Position the `<td>` (a table-cell IS a valid containing block) or wrap the
                content in a `<div class="relative">`.

                Offending rows:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function everyClickableRowDelegatesToARealAnchor(): void
    {
        $offenders = $this->scan(self::clickableRowsWithoutARealAnchor(...));

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                A row styled `cursor-pointer` promises the user it is clickable. Keeping that
                promise requires both halves of the contract:

                  1. the `<tr>` carries the `row-link` Stimulus controller and
                     `data-action="click->row-link#navigate"`, and
                  2. its body contains an anchor marked `data-row-link-target="link"`.

                Without (2) the row is a dead hand-cursor. Without (1) the only way the whole
                row can be clickable is an overlay, which is the undefined-CSS bug this file
                exists to prevent. A row that only *looks* clickable is worse than a plain
                row: users click, nothing happens, and they conclude the page is broken.

                Offending rows:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function everyRowAnchorInsideATurboFrameEscapesToTop(): void
    {
        $offenders = $this->scan(self::rowAnchorsNotEscapingTheirTurboFrame(...));

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                A row anchor inside a `<turbo-frame>` must carry `data-turbo-frame="_top"`.
                Without it Turbo swaps the destination page's markup INTO the table's frame:
                the user clicks a report row and gets a report-detail page rendered inside
                the reports table, with no navigation, no heading and no way back except the
                browser Back button. It is not an error anywhere — just a page that looks
                shattered.

                Offending anchors:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function everyRowLoopProducesAPerRowDestination(): void
    {
        $offenders = $this->scan(self::rowLoopsWithoutAPerRowDestination(...));

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                Every row in this loop resolves to the same URL: no `href` on a row anchor
                mentions the loop variable, so the destination cannot vary per row. That is
                the same user-visible symptom as the overlay bug — "whichever row I click, I
                land on the same record" — reached from the other direction.

                Honest limitation: this is a STRUCTURAL check. It cannot detect the CSS
                hit-testing failure that originally caused the symptom, because in that bug
                the hrefs were all perfectly distinct. What it locks in is the structure that
                makes hit-testing irrelevant — one real per-row anchor whose href is derived
                from the row — so the two guards are complementary, not redundant.

                Offending loops:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function theGuardsActuallyScanTheTemplateTree(): void
    {
        // A guard that silently scans zero files passes forever. If templates/
        // moves or the extension changes, fail here rather than turning every
        // guard above into a no-op.
        $templates = ProjectSource::files('templates', 'twig');

        self::assertGreaterThan(50, \count($templates), 'The template tree should hold dozens of .twig files — the guards below are scanning nothing.');
        self::assertArrayHasKey('templates/dashboard/_reports_table.html.twig', $templates, 'The reports table is the surface the row-navigation bug was reported on; it must be in scope.');
    }

    #[Test]
    public function overlayGuardItselfFailsOnAnOverlayAnchor(): void
    {
        $violation = <<<'TWIG'
            <table class="table">
                <tbody>
                    <tr class="cursor-pointer relative">
                        <td>
                            <a href="/app/reports/1" class="absolute inset-0 z-10" aria-label="Open"></a>
                            acme.example
                        </td>
                    </tr>
                </tbody>
            </table>
            TWIG;

        self::assertSame([5], self::overlayAnchorsInsideTables($violation));
        self::assertSame([], self::overlayAnchorsInsideTables(str_replace('absolute inset-0 z-10', 'link link-hover', $violation)));
    }

    #[Test]
    public function overlayGuardIgnoresOverlaysOutsideTablesAndInsideComments(): void
    {
        // Card surfaces legitimately use the stretched-link pattern: a `<div>` IS
        // a valid containing block, so the undefined-CSS problem does not exist
        // there. And several templates carry comments quoting the banned pattern
        // as a warning — flagging those would make the guard punish its own
        // documentation.
        $card = '<div class="card relative"><a href="/app/domains/1" class="absolute inset-0"></a></div>';
        $commented = '<table><tr><td>{# never reinstate <a class="absolute inset-0"> here #}</td></tr></table>';

        self::assertSame([], self::overlayAnchorsInsideTables($card));
        self::assertSame([], self::overlayAnchorsInsideTables($commented));
    }

    #[Test]
    public function positionedRowGuardItselfFailsOnARelativeRow(): void
    {
        self::assertSame([1], self::positionedTableRows('<tr class="hover:bg-base-200 cursor-pointer relative">'));
        self::assertSame([1], self::positionedTableRows('<tr class="sm:relative">'), 'A Tailwind variant applies the same property and must not slip through.');
        self::assertSame([], self::positionedTableRows('<tr class="hover:bg-base-200 cursor-pointer">'));
    }

    #[Test]
    public function clickableRowGuardItselfFailsOnADeadRow(): void
    {
        $withoutAnchor = '<tr class="cursor-pointer" data-controller="row-link" data-action="click->row-link#navigate"><td>acme.example</td></tr>';
        $withoutController = '<tr class="cursor-pointer"><td><a href="/x" data-row-link-target="link">acme.example</a></td></tr>';
        $correct = '<tr class="cursor-pointer" data-controller="row-link" data-action="click->row-link#navigate"><td><a href="/x" data-row-link-target="link">acme.example</a></td></tr>';

        self::assertSame([1], self::clickableRowsWithoutARealAnchor($withoutAnchor));
        self::assertSame([1], self::clickableRowsWithoutARealAnchor($withoutController));
        self::assertSame([], self::clickableRowsWithoutARealAnchor($correct));
    }

    #[Test]
    public function turboFrameGuardItselfFailsOnATrappedRowAnchor(): void
    {
        $trapped = '<turbo-frame id="reports-table"><table><tr><td><a href="/app/reports/1" data-row-link-target="link">x</a></td></tr></table></turbo-frame>';
        $escaping = str_replace('data-row-link-target="link"', 'data-row-link-target="link" data-turbo-frame="_top"', $trapped);
        $unframed = '<table><tr><td><a href="/app/reports/1" data-row-link-target="link">x</a></td></tr></table>';

        self::assertSame([1], self::rowAnchorsNotEscapingTheirTurboFrame($trapped));
        self::assertSame([], self::rowAnchorsNotEscapingTheirTurboFrame($escaping));
        self::assertSame([], self::rowAnchorsNotEscapingTheirTurboFrame($unframed), 'Outside a frame there is nothing to escape from.');
    }

    #[Test]
    public function perRowDestinationGuardItselfFailsOnACollapsedTable(): void
    {
        $collapsed = <<<'TWIG'
            {% for report in reports %}
                <tr><td><a href="{{ path('dashboard_reports') }}" data-row-link-target="link">{{ report.domainName }}</a></td></tr>
            {% endfor %}
            TWIG;
        $perRow = str_replace("path('dashboard_reports')", "path('dashboard_report_detail', { id: report.reportId })", $collapsed);

        self::assertSame([1], self::rowLoopsWithoutAPerRowDestination($collapsed));
        self::assertSame([], self::rowLoopsWithoutAPerRowDestination($perRow));
    }

    /**
     * @param callable(string): list<int> $detector
     *
     * @return list<string>
     */
    private function scan(callable $detector): array
    {
        $offenders = [];
        foreach (ProjectSource::files('templates', 'twig') as $path => $contents) {
            foreach ($detector($contents) as $line) {
                $offenders[] = sprintf('%s:%d', $path, $line);
            }
        }

        return $offenders;
    }

    /**
     * @return list<int> line numbers
     */
    private static function overlayAnchorsInsideTables(string $twig): array
    {
        $markup = ProjectSource::stripTwigComments($twig);
        // A `<tr>` on its own is enough context: several row partials are
        // fragments that never contain the `<table>` element themselves.
        $tableRegions = [...ProjectSource::regions($markup, 'table'), ...ProjectSource::regions($markup, 'tr')];

        $lines = [];
        foreach (ProjectSource::openingTags($markup, 'a') as $anchor) {
            if (!ProjectSource::isInsideAnyRegion($anchor['offset'], $tableRegions)) {
                continue;
            }

            $tokens = ProjectSource::classTokens($anchor['source']);
            if (!ProjectSource::hasUtility($tokens, 'absolute') && !ProjectSource::hasUtility($tokens, 'fixed')) {
                continue;
            }

            $lines[] = ProjectSource::lineOfOffset($markup, $anchor['offset']);
        }

        return $lines;
    }

    /**
     * @return list<int> line numbers
     */
    private static function positionedTableRows(string $twig): array
    {
        $markup = ProjectSource::stripTwigComments($twig);

        $lines = [];
        foreach (ProjectSource::openingTags($markup, 'tr') as $row) {
            if (!ProjectSource::hasUtility(ProjectSource::classTokens($row['source']), 'relative')) {
                continue;
            }

            $lines[] = ProjectSource::lineOfOffset($markup, $row['offset']);
        }

        return $lines;
    }

    /**
     * @return list<int> line numbers
     */
    private static function clickableRowsWithoutARealAnchor(string $twig): array
    {
        $markup = ProjectSource::stripTwigComments($twig);

        $lines = [];
        foreach (ProjectSource::elements($markup, 'tr') as $row) {
            if (!ProjectSource::hasUtility(ProjectSource::classTokens($row['openTag']), 'cursor-pointer')) {
                continue;
            }

            $delegates = str_contains($row['openTag'], 'row-link')
                && str_contains($row['openTag'], 'click->row-link#navigate');

            if ($delegates && str_contains($row['inner'], self::ROW_LINK_TARGET)) {
                continue;
            }

            $lines[] = ProjectSource::lineOfOffset($markup, $row['start']);
        }

        return $lines;
    }

    /**
     * @return list<int> line numbers
     */
    private static function rowAnchorsNotEscapingTheirTurboFrame(string $twig): array
    {
        $markup = ProjectSource::stripTwigComments($twig);
        $frames = ProjectSource::regions($markup, 'turbo-frame');
        if ([] === $frames) {
            return [];
        }

        $lines = [];
        foreach (ProjectSource::openingTags($markup, 'a') as $anchor) {
            if ('link' !== ProjectSource::attributeValue($anchor['source'], 'data-row-link-target')) {
                continue;
            }

            if (!ProjectSource::isInsideAnyRegion($anchor['offset'], $frames)) {
                continue;
            }

            if ('_top' === ProjectSource::attributeValue($anchor['source'], 'data-turbo-frame')) {
                continue;
            }

            $lines[] = ProjectSource::lineOfOffset($markup, $anchor['offset']);
        }

        return $lines;
    }

    /**
     * @return list<int> line numbers of the offending `{% for %}` openers
     */
    private static function rowLoopsWithoutAPerRowDestination(string $twig): array
    {
        $markup = ProjectSource::stripTwigComments($twig);
        $loops = ProjectSource::twigForLoops($markup);
        if ([] === $loops) {
            return [];
        }

        /** @var array<int, array{variable: string, hrefs: list<string>}> $byLoop */
        $byLoop = [];
        foreach (ProjectSource::openingTags($markup, 'a') as $anchor) {
            if ('link' !== ProjectSource::attributeValue($anchor['source'], 'data-row-link-target')) {
                continue;
            }

            // Innermost enclosing loop only: an outer loop's variable has no
            // business appearing in an inner row's href.
            $innermost = null;
            foreach ($loops as $index => $loop) {
                if ($anchor['offset'] < $loop['start'] || $anchor['offset'] >= $loop['end']) {
                    continue;
                }

                if (null === $innermost || $loop['start'] > $loops[$innermost]['start']) {
                    $innermost = $index;
                }
            }

            if (null === $innermost) {
                continue;
            }

            $byLoop[$innermost] ??= ['variable' => $loops[$innermost]['variable'], 'hrefs' => []];
            $byLoop[$innermost]['hrefs'][] = (string) ProjectSource::attributeValue($anchor['source'], 'href');
        }

        $lines = [];
        foreach ($byLoop as $index => $loop) {
            foreach ($loop['hrefs'] as $href) {
                // One per-row destination is enough: a table may legitimately
                // fall back to a shared page for rows that have no record of
                // their own (a sender with no inventory row yet), as long as the
                // loop is *capable* of producing distinct destinations.
                if (1 === preg_match('/\b'.preg_quote($loop['variable'], '/').'\b/', $href)) {
                    continue 2;
                }
            }

            $lines[] = ProjectSource::lineOfOffset($markup, $loops[$index]['start']);
        }

        sort($lines);

        return $lines;
    }
}

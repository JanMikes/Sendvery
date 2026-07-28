import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import type { AxeResults, Result } from 'axe-core';

const BASELINE_FILE = resolve(__dirname, '../axe-baseline.json');

/**
 * WCAG 2.0/2.1 A and AA. Deliberately not axe's "best-practice" tag: those are
 * opinions, and mixing them into the same gate as a real WCAG failure teaches
 * everyone to ignore the gate.
 */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * The Symfony web debug toolbar is injected into every dev page and is not part
 * of the product — nobody can fix its markup and no customer ever sees it. It is
 * the only thing excluded from these scans; NO axe rule is disabled, because a
 * disabled rule is a green light that means nothing.
 */
const NOT_PART_OF_THE_PRODUCT = ['.sf-toolbar', '.sf-minitoolbar', '[id^="sfwdt"]'];

export type BaselinedViolation = {
    impact: string;
    nodes: number;
    help: string;
    /** A few example targets, for a human reading the baseline. Not compared. */
    sample: string[];
};

export type AxeBaseline = Record<string, Record<string, BaselinedViolation>>;

function loadBaseline(): AxeBaseline {
    if (!existsSync(BASELINE_FILE)) {
        return {};
    }

    return JSON.parse(readFileSync(BASELINE_FILE, 'utf8')) as AxeBaseline;
}

function summarise(violations: Result[]): Record<string, BaselinedViolation> {
    const summary: Record<string, BaselinedViolation> = {};

    for (const violation of violations.slice().sort((a, b) => a.id.localeCompare(b.id))) {
        summary[violation.id] = {
            impact: violation.impact ?? 'unknown',
            nodes: violation.nodes.length,
            help: violation.help,
            sample: violation.nodes.slice(0, 3).map((node) => node.target.join(' ')),
        };
    }

    return summary;
}

/**
 * Wait for the two webfonts to be usable, not merely for "no load is pending".
 *
 * `document.fonts.ready` on its own is not enough on a COLD browser process: it
 * can resolve before the cross-origin Google Fonts stylesheet has been parsed,
 * so there is no pending @font-face for it to wait on and it settles against an
 * empty set. Measured consequence — `/app` reported 34 color-contrast nodes
 * instead of 33 on roughly one cold start in four, while being perfectly stable
 * within a warm process.
 *
 * `document.fonts.load()` names the families instead of hoping they are already
 * pending. It degrades correctly in every failure mode we care about: it
 * resolves empty if the family is unknown, and rejects (caught here) if the CDN
 * is blocked — in which case we scan the fallback rendering rather than fail.
 */
async function waitForFonts(page: Page): Promise<void> {
    await page.evaluate(async () => {
        await Promise.all([
            document.fonts.load('16px Inter').catch(() => undefined),
            document.fonts.load('16px "JetBrains Mono"').catch(() => undefined),
        ]);
        await document.fonts.ready;
    });
}

/**
 * ApexCharts renders its SVG asynchronously after a dynamic `import()`, so on a
 * cold process the module fetch alone can outlast page load. Scanning before it
 * lands would measure a DOM no user ever sees.
 */
async function waitForCharts(page: Page): Promise<void> {
    if (0 === (await page.locator('[data-controller~="chart"]').count())) {
        return;
    }

    await page.waitForSelector('[data-controller~="chart"] .apexcharts-canvas', { state: 'attached' });
}

export async function scan(page: Page): Promise<AxeResults> {
    // Contrast is measured from rendered pixels, so both of these must settle
    // before axe looks — otherwise the baseline records network timing.
    await waitForFonts(page);
    await waitForCharts(page);

    let builder = new AxeBuilder({ page }).withTags(TAGS);

    for (const selector of NOT_PART_OF_THE_PRODUCT) {
        builder = builder.exclude(selector);
    }

    return builder.analyze();
}

export type BaselineComparison = {
    /** Empty means "no new accessibility debt on this page". */
    regressions: string[];
    /** Reported, never fatal: rules that improved, so the baseline can be tightened. */
    notes: string[];
    found: Record<string, BaselinedViolation>;
};

/**
 * Flip to false to stop gating node counts and gate only new rule ids.
 *
 * On by default: a known-failing rule quietly spreading to twenty more nodes is
 * real new debt, and gating only rule ids would let it through.
 */
const GATE_NODE_COUNT_INCREASES = true;

/**
 * Pages whose node count is not yet reproducible, with the measured spread.
 *
 * This is NOT a general escape hatch — the default is zero, and every entry has
 * to name the defect that causes it and disappear when that defect is fixed.
 *
 * EMPTY, and the way it emptied is the point. `dashboard-overview` used to allow
 * +1: GetAllReports.php ordered by `date_range_end DESC` with no tiebreaker, and
 * the seeded data puts three reports on every date, so WHICH ten rows filled the
 * Recent Reports card differed between identical requests. The three seeded
 * domains have different pass rates, so the mix of amber and green pass-rate
 * labels — and therefore how many of them fail contrast — moved with it.
 * Measured then over 26 cold browser starts: 33 nodes on 23, 34 on 3.
 *
 * `GetAllReports` now orders by `date_range_end DESC, dr.id DESC`. Re-measured
 * over 30 cold browser starts (one `playwright test` process each, so a fresh
 * Chromium every time — the instability never showed within a warm process):
 * 33 nodes on 30 of 30, zero failures. The allowance is gone and the gate is
 * back to exact.
 */
const MEASURED_NODE_SPREAD: Record<string, number> = {};

/**
 * A ratchet, not a snapshot. It fails on new accessibility debt: a rule firing on
 * a page where the baseline does not record it (an image without alt text, a
 * control without a label, a broken ARIA reference, a skipped heading level), or
 * an already-failing rule spreading to more nodes.
 *
 * What it deliberately does NOT do is assert exact equality, because that makes
 * every accessibility FIX break CI — which is how a baseline stops being a
 * measurement and becomes a reason not to improve anything. Fewer nodes, or a
 * rule that stops firing, passes and is reported so the baseline can be burned
 * down.
 */
export function compareToBaseline(pageKey: string, results: AxeResults): BaselineComparison {
    const found = summarise(results.violations);
    const baseline = loadBaseline();
    const known = baseline[pageKey] ?? {};

    const regressions: string[] = [];
    const notes: string[] = [];

    for (const [ruleId, violation] of Object.entries(found)) {
        const recorded = known[ruleId];

        if (undefined === recorded) {
            regressions.push(
                `NEW ${violation.impact} violation "${ruleId}" on ${violation.nodes} node(s): ${violation.help} ` +
                    `(e.g. ${violation.sample.join(' | ')})`,
            );
            continue;
        }

        const allowance = MEASURED_NODE_SPREAD[pageKey] ?? 0;

        if (violation.nodes > recorded.nodes + allowance) {
            const message =
                `"${ruleId}" (${violation.impact}) spread from ${recorded.nodes} to ${violation.nodes} node(s)` +
                (0 === allowance ? '' : ` (measured spread on this page allows +${allowance})`) +
                `: ${violation.help} (e.g. ${violation.sample.join(' | ')})`;

            if (GATE_NODE_COUNT_INCREASES) {
                regressions.push(message);
            } else {
                notes.push(message);
            }
        } else if (violation.nodes > recorded.nodes) {
            notes.push(
                `"${ruleId}" reported ${violation.nodes} nodes against a baseline of ${recorded.nodes} — within this page's measured spread`,
            );
        } else if (violation.nodes < recorded.nodes) {
            notes.push(
                `"${ruleId}" improved from ${recorded.nodes} to ${violation.nodes} node(s) — the baseline can be tightened`,
            );
        }
    }

    for (const ruleId of Object.keys(known)) {
        if (undefined === found[ruleId]) {
            notes.push(`"${ruleId}" no longer fires — the baseline can be tightened`);
        }
    }

    return { regressions, notes, found };
}

/**
 * `UPDATE_AXE_BASELINE=1 npx playwright test accessibility` rewrites the
 * committed baseline. Only ever run it deliberately: it accepts whatever the app
 * currently does.
 */
export const IS_UPDATING_BASELINE = '1' === process.env.UPDATE_AXE_BASELINE;

export function writeBaselineEntry(pageKey: string, found: Record<string, BaselinedViolation>): void {
    const baseline = loadBaseline();
    baseline[pageKey] = found;

    const ordered: AxeBaseline = {};
    for (const key of Object.keys(baseline).sort()) {
        ordered[key] = baseline[key];
    }

    writeFileSync(BASELINE_FILE, `${JSON.stringify(ordered, null, 4)}\n`);
}

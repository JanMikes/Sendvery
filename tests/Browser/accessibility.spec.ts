import { SEEDED, expect, test } from './support/harness';
import { IS_UPDATING_BASELINE, compareToBaseline, scan, writeBaselineEntry } from './support/axe';
import { seedDemoData } from './support/console';

/*
 * The axe-core baseline that docs/cx-improvement-backlog.md deferred under
 * TASK-018: "axe-core baseline: SKIP — no Panther/Cypress infrastructure in the
 * test suite". The infrastructure now exists, so the debt is measured.
 *
 * It is a RATCHET, not a pass/fail gate: the app is not WCAG-clean today, and a
 * gate that fails on day one gets switched off within a week. What CI enforces is
 * that no page acquires a NEW violation and that no known violation spreads to
 * more nodes. Fixes pass and are reported so the baseline can be burned down. See
 * tests/Browser/axe-baseline.json for exactly what is currently owed.
 *
 * No axe rule is disabled anywhere. The only exclusion is the Symfony web debug
 * toolbar, which is dev-only chrome nobody can fix and no customer sees.
 */

/*
 * Re-seed rather than trusting spec order. alerts-bulk.spec.ts marks every
 * seeded alert as read, which changes the alert rows' background tint and drops
 * the unread line — so whether this file runs before or after it would decide
 * how many nodes color-contrast reports. A baseline that depends on file
 * ordering fails CI for reasons unrelated to the change, which is exactly how a
 * ratchet loses its credibility.
 */
test.beforeAll(() => {
    seedDemoData();
});

const PAGES = [
    { key: 'dashboard-overview', url: '/app' },
    { key: 'reports-list', url: '/app/reports' },
    { key: 'alerts-list', url: '/app/alerts' },
    { key: 'domains-list', url: '/app/domains' },
] as const;

for (const { key, url } of PAGES) {
    test(`${key} carries no accessibility violations beyond the recorded baseline`, async ({ page }) => {
        await page.goto(url);

        const results = await scan(page);

        // Non-vacuity: a scan that evaluated nothing would "pass" every ratchet
        // check. If axe never injected, or the page was blank, passes is empty.
        expect(results.passes.length, 'axe must actually have evaluated this page').toBeGreaterThan(0);

        if (IS_UPDATING_BASELINE) {
            writeBaselineEntry(key, compareToBaseline(key, results).found);

            return;
        }

        const { regressions, notes } = compareToBaseline(key, results);

        for (const note of notes) {
            test.info().annotations.push({ type: 'axe-note', description: note });
        }

        expect(regressions, `${url} introduced accessibility violations not in the baseline`).toEqual([]);
    });
}

test('a domain detail page carries no accessibility violations beyond the recorded baseline', async ({ page }) => {
    await page.goto('/app/domains');

    const links = page.locator('a[aria-label^="Open "]');
    await expect(links).toHaveCount(SEEDED.domains);

    // broken.example: the domain whose guided setup has an action-required step,
    // which is the most complex markup in the dashboard.
    const href = await page.locator('a[aria-label="Open broken.example"]').getAttribute('href');
    await page.goto(href as string);

    const results = await scan(page);
    expect(results.passes.length, 'axe must actually have evaluated this page').toBeGreaterThan(0);

    if (IS_UPDATING_BASELINE) {
        writeBaselineEntry('domain-detail', compareToBaseline('domain-detail', results).found);

        return;
    }

    const { regressions, notes } = compareToBaseline('domain-detail', results);

    for (const note of notes) {
        test.info().annotations.push({ type: 'axe-note', description: note });
    }

    expect(regressions, 'the domain detail page introduced accessibility violations not in the baseline').toEqual([]);
});

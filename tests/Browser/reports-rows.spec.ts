import { Locator, Page } from '@playwright/test';
import { SEEDED, expect, test } from './support/harness';

/*
 * The defect this file exists for: every row of the reports list opened the SAME
 * report. 3400+ PHPUnit tests could not see it and never will — the hrefs were
 * correct and distinct in the HTML, and a browser routed every click to one
 * stretched-link overlay because `position: relative` on a `<tr>` is undefined
 * in CSS 2.1. It shipped twice: once on /app/reports, then again on /app.
 *
 * Two assertions are needed, and each catches a different half:
 *
 *  - the rows' destinations must DIFFER. This is what catches the data-side
 *    version (a query collapsing rows, a DTO reading the wrong column, a
 *    template hardcoding one id) — a version where clicking row 3 does land on
 *    the href row 3 advertises, because every row advertises the same one.
 *  - clicking row 3 must land on ROW 3's destination. This is what catches the
 *    hit-testing version, where the hrefs are all correct and the browser
 *    routes the click to a different row's link.
 *
 * Clicks go through Playwright locators, never page.mouse.click(x, y): raw
 * coordinates do not scroll, so a below-the-fold row gets clicked at an
 * off-screen point and reports a failure that is the test's fault, not the
 * app's.
 */

const REPORT_DETAIL_PATH = /^\/app\/reports\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

async function destinations(rows: Locator): Promise<string[]> {
    return rows.locator('a[data-row-link-target="link"]').evaluateAll((anchors) =>
        anchors.map((anchor) => anchor.getAttribute('href') ?? ''),
    );
}

/**
 * Clicks a cell that is NOT the anchor, which is the interaction the bug broke:
 * "click anywhere on the row" is delegated by the row-link Stimulus controller,
 * and clicking the anchor itself would prove nothing about that delegation.
 */
async function clickRowBody(row: Locator): Promise<void> {
    const reporterCell = row.locator('td').nth(2);
    await expect(reporterCell).toBeVisible();
    await reporterCell.click();
}

async function assertOpensItsOwnReport(page: Page, rows: Locator, index: number): Promise<void> {
    const hrefs = await destinations(rows);

    expect(new Set(hrefs).size, `each row must link to its own report, got: ${hrefs.join(', ')}`).toBe(hrefs.length);
    for (const href of hrefs) {
        expect(href).toMatch(REPORT_DETAIL_PATH);
    }

    const expected = hrefs[index];
    await clickRowBody(rows.nth(index));

    await expect(page).toHaveURL(new RegExp(`${expected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
}

test('the third row of the reports list opens the third row\'s own report', async ({ page }) => {
    await page.goto('/app/reports');

    const rows = page.locator('turbo-frame#reports-table tbody tr');
    await expect(rows).toHaveCount(SEEDED.reportsPerPage);

    await assertOpensItsOwnReport(page, rows, 2);
});

test('the third recent report on the dashboard opens the third report\'s own page', async ({ page }) => {
    await page.goto('/app');

    // Scoped to the Recent Reports card, not "any table on /app" — the overview
    // renders several tables and picking the wrong one would silently pass.
    const rows = page.locator('table:has(a[data-row-link-target="link"][href^="/app/reports/"]) tbody tr');
    await expect(rows).toHaveCount(SEEDED.recentReportsOnOverview);

    await assertOpensItsOwnReport(page, rows, 2);
});

test('a report row stays middle-clickable and copyable because it is a real link', async ({ page }) => {
    await page.goto('/app/reports');

    const firstLink = page.locator('turbo-frame#reports-table tbody tr').first().locator('a[data-row-link-target="link"]');

    await expect(firstLink).toBeVisible();
    await expect(firstLink).toHaveAttribute('href', REPORT_DETAIL_PATH);

    // Keyboard reachability is the accessibility half of the same fix: an
    // overlay div is not focusable, an anchor is.
    await firstLink.focus();
    await expect(firstLink).toBeFocused();
});

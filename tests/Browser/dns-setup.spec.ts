import { SEEDED, expect, test } from './support/harness';

/*
 * The guided DNS setup surface is the page a new customer spends their first
 * hour on, and its one interactive element — "Copy" — is pure clipboard API.
 * A server-side test can prove the button is in the markup; only a browser can
 * prove it puts the record on the clipboard, and that the value it copies is
 * the value shown next to it. Those two disagreeing is the failure that costs a
 * customer an afternoon at their DNS provider.
 */

/** The seeded domain with no SPF record, so its setup guide has real work in it. */
const DOMAIN_WITH_WORK = 'broken.example';

test('the guided DNS setup renders for a domain that still has work to do', async ({ page }) => {
    await page.goto('/app/domains');

    const domainLinks = page.locator('a[aria-label^="Open "]');
    await expect(domainLinks).toHaveCount(SEEDED.domains);

    // Derived from the page, not from a seeded UUID.
    const href = await page.locator(`a[aria-label="Open ${DOMAIN_WITH_WORK}"]`).getAttribute('href');
    expect(href).toMatch(/^\/app\/domains\/[0-9a-f-]{36}$/);

    await page.goto(href as string);

    const setup = page.locator('[data-testid="guided-dns-setup"]');
    await expect(setup).toBeVisible();
    await expect(setup).toHaveAttribute('data-setup-mode', 'compact');

    // A headline is the whole point of the surface: it is the one sentence that
    // tells the user what to do next. An empty one is a broken page that still
    // returns 200.
    const headline = setup.locator('[data-testid="guided-dns-setup-headline"]');
    await expect(headline).toBeVisible();
    await expect(headline).not.toHaveText('');

    await expect(setup.locator('[data-testid="dns-record-recommendations"]')).toBeVisible();
    await expect(setup.locator('[data-testid="guided-setup-tier-action_required"]')).toBeVisible();
});

test('the copy button puts the exact record shown on the clipboard', async ({ page }) => {
    await page.goto('/app/domains');
    const href = await page.locator(`a[aria-label="Open ${DOMAIN_WITH_WORK}"]`).getAttribute('href');
    await page.goto(href as string);

    const record = page.locator('[data-testid="guided-dns-record"]').first();
    await expect(record).toBeVisible();

    const shown = (await record.locator('[data-testid="guided-dns-record-final"]').textContent())?.trim();
    expect(shown).toBeTruthy();

    const copyButton = record.locator('[data-testid="guided-dns-record-copy"]');
    // A locator click, never coordinates: this button sits well below the fold
    // on the domain detail page, and Playwright scrolls it into view for us.
    await copyButton.click();

    // Inline confirmation — the only feedback the user gets that anything
    // happened.
    await expect(copyButton).toHaveText('Copied!');

    const clipboard = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboard.trim()).toBe(shown);
});

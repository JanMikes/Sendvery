import { SEEDED, expect, test } from './support/harness';

/*
 * TomSelect replaces the native <select multiple> on the reports filter. The
 * thing that can break invisibly is the contract it is built on: the original
 * <select> must STAY in the DOM with its name and its selected state, because
 * that is what makes the GET form keep submitting `domain[]=…`. If TomSelect
 * ever detached it, or stopped syncing the selection back, the widget would look
 * perfect and the filter would quietly stop filtering — a server-side test would
 * see the untouched template and pass.
 */

test('picking a domain in the enhanced filter still submits domain[] and narrows the list', async ({ page }) => {
    await page.goto('/app/reports');

    const domainField = page.locator('label:has(select[name="domain[]"])');
    const nativeSelect = domainField.locator('select[name="domain[]"]');

    // Progressive enhancement, asserted rather than assumed: the widget is
    // present AND the element that carries the form value survived it.
    await expect(domainField.locator('.ts-wrapper')).toBeVisible();
    await expect(nativeSelect).toHaveCount(1);
    // TomSelect rewrites the attribute's serialised value, so assert the DOM
    // property: what matters is that the element still submits multiple values.
    await expect(nativeSelect).toHaveJSProperty('multiple', true);

    const options = await nativeSelect.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.getAttribute('value') ?? '', label: node.textContent?.trim() ?? '' })),
    );
    expect(options).toHaveLength(SEEDED.domains);

    // Expectations come from the page, never from a seeded UUID: a hardcoded id
    // breaks on the next re-seed and tells you nothing about the filter.
    const chosen = options[0];
    expect(chosen.value).not.toBe('');
    expect(chosen.label).not.toBe('');

    await domainField.locator('.ts-control').click();
    await domainField.locator(`.ts-dropdown [data-value="${chosen.value}"]`).click();

    // The selection has to land on the native element, not only in TomSelect's
    // internal state — that is the whole mechanism under test.
    await expect(nativeSelect).toHaveValues([chosen.value]);

    await page.getByRole('button', { name: 'Filter' }).click();

    await expect(page).toHaveURL(new RegExp(`domain%5B%5D=${chosen.value}`));

    const rows = page.locator('turbo-frame#reports-table tbody tr');
    // Each seeded domain has its own 30 reports, so one domain still fills page 1.
    await expect(rows).toHaveCount(SEEDED.reportsPerPage);

    const domainCells = await rows.locator('td:nth-child(2)').allInnerTexts();
    expect(domainCells).toHaveLength(SEEDED.reportsPerPage);
    for (const cell of domainCells) {
        expect(cell.trim()).toBe(chosen.label);
    }
});

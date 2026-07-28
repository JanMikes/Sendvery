import { SEEDED, expect, test } from './support/harness';

/*
 * The alerts bulk toolbar is three pieces of behaviour that only exist in the
 * browser: the header checkbox drives every row checkbox, the "N selected"
 * label and the selection-scoped buttons follow it, and the resulting POST
 * carries both the ids and the CSRF token. Server-side tests can post the form
 * directly, which skips all three.
 */

test('select-all then a bulk action marks every listed alert as read', async ({ page }) => {
    await page.goto('/app/alerts');

    const rowCheckboxes = page.locator('input[name="alertIds[]"]');
    await expect(rowCheckboxes).toHaveCount(SEEDED.alerts);

    const selectAll = page.locator('input[data-alert-selection-target="selectAll"]');
    const count = page.locator('[data-alert-selection-target="count"]');
    const markRead = page.locator('button[name="action"][value="mark_read"]');

    // The toolbar is always visible, and its selection-scoped buttons start
    // disabled so that visibility is honest instead of a trap.
    await expect(count).toHaveText('0 selected');
    await expect(markRead).toBeDisabled();
    await expect(page.getByText(`${SEEDED.alerts} unread alerts`)).toBeVisible();

    await selectAll.check();

    await expect(count).toHaveText(`${SEEDED.alerts} selected`);
    await expect(markRead).toBeEnabled();
    for (let index = 0; index < SEEDED.alerts; index += 1) {
        await expect(rowCheckboxes.nth(index)).toBeChecked();
    }

    await markRead.click();

    await expect(page.locator('.alert-success')).toHaveText(new RegExp(`Marked ${SEEDED.alerts} alerts as read`));

    // The real outcome, not just the confirmation message: nothing is unread any
    // more, so the unread line is gone and the toolbar has reset.
    await expect(page.getByText('unread alert')).toHaveCount(0);
    await expect(page.locator('[data-alert-selection-target="count"]')).toHaveText('0 selected');
    await expect(page.locator('input[name="alertIds[]"]')).toHaveCount(SEEDED.alerts);
});

test('clearing the selection disables the actions again', async ({ page }) => {
    await page.goto('/app/alerts');

    const selectAll = page.locator('input[data-alert-selection-target="selectAll"]');
    const count = page.locator('[data-alert-selection-target="count"]');
    const snooze = page.locator('button[name="action"][value="snooze_7d"]');

    await selectAll.check();
    await expect(snooze).toBeEnabled();

    await page.getByRole('button', { name: 'Clear' }).click();

    await expect(count).toHaveText('0 selected');
    await expect(snooze).toBeDisabled();
    await expect(selectAll).not.toBeChecked();
});

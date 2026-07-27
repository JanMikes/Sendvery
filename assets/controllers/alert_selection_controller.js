import { Controller } from '@hotwired/stimulus';

/*
 * Drives the per-row checkbox + persistent toolbar UX on the Alerts list.
 *
 * Targets:
 *   toolbar    — the bulk action bar. Always visible: a panel that only appears
 *                after the first click hides the fact that bulk actions exist.
 *   count      — the "N selected" label inside the toolbar.
 *   scoped     — buttons that act on the selection. Disabled at 0 selected so
 *                the always-visible panel is honest instead of a trap.
 *   selectAll  — the header "select all" checkbox, driven into the
 *                indeterminate state on a partial selection.
 *
 * Bound on the wrapping <form>; per-row checkboxes call updateCount() on
 * change, the clear button calls clearAll(), the header box calls toggleAll().
 */
export default class extends Controller {
    static targets = ['toolbar', 'count', 'scoped', 'selectAll'];

    /*
     * Browsers restore checkbox state on back-navigation without firing
     * `change`, so a fresh render can already have rows checked. Syncing on
     * connect keeps the label, the disabled buttons and the select-all box
     * honest in that case.
     */
    connect() {
        this.updateCount();
    }

    updateCount() {
        const boxes = this.rowCheckboxes();
        const n = boxes.filter((cb) => cb.checked).length;

        this.countTarget.textContent = `${n} selected`;

        this.scopedTargets.forEach((button) => {
            button.disabled = n === 0;
        });

        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = n > 0 && n === boxes.length;
            this.selectAllTarget.indeterminate = n > 0 && n < boxes.length;
        }
    }

    toggleAll(event) {
        const shouldCheck = event.currentTarget.checked;
        this.rowCheckboxes().forEach((cb) => {
            cb.checked = shouldCheck;
        });
        this.updateCount();
    }

    clearAll() {
        this.rowCheckboxes().forEach((cb) => {
            cb.checked = false;
        });
        this.updateCount();
    }

    rowCheckboxes() {
        return Array.from(this.element.querySelectorAll('input[type=checkbox][name="alertIds[]"]'));
    }
}

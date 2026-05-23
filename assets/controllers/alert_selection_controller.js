import { Controller } from '@hotwired/stimulus';

/*
 * Drives the per-row checkbox + sticky toolbar UX on the Alerts list.
 *
 * Targets:
 *   toolbar — the bulk action bar that's `class="hidden"` by default.
 *   count   — the "N selected" label inside the toolbar.
 *
 * Bound on the wrapping <form>; per-row checkboxes call updateCount() on
 * change, the clear button calls clearAll().
 */
export default class extends Controller {
    static targets = ['toolbar', 'count'];

    updateCount() {
        const checked = this.element.querySelectorAll('input[type=checkbox][name="alertIds[]"]:checked');
        const n = checked.length;
        this.toolbarTarget.classList.toggle('hidden', n === 0);
        this.countTarget.textContent = `${n} selected`;
    }

    clearAll() {
        this.element.querySelectorAll('input[type=checkbox][name="alertIds[]"]').forEach((cb) => {
            cb.checked = false;
        });
        this.updateCount();
    }
}

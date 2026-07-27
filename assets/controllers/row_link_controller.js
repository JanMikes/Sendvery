import { Controller } from '@hotwired/stimulus';

/*
 * Generic "click anywhere on the row to open it" behaviour.
 *
 * WHY this exists instead of the old stretched-link overlay: the previous
 * pattern put `<a class="absolute inset-0 z-10">` inside a `<td>` and relied on
 * `<tr class="relative">` to be the anchor's containing block. Per CSS 2.1
 * §9.3.1 the effect of `position: relative` on `display: table-row` is
 * UNDEFINED, and daisyUI's `.table` is itself `position: relative` — so in
 * browsers that ignore positioning on `<tr>`, every row's overlay sized itself
 * to the whole `<table>`, they all stacked at the same z-index, and the last
 * one in DOM order swallowed every click. Result: every row opened the bottom
 * row's record. This controller replaces that hack with a real, visible anchor
 * plus a click delegate, so hit-testing no longer depends on undefined CSS.
 *
 * CONTRACT (reusable verbatim by any dashboard table):
 *
 *   <tr class="cursor-pointer" {{ stimulus_controller('row-link') }}
 *       data-action="click->row-link#navigate">
 *       <td>
 *           <a href="{{ path('some_detail', { id: row.id }) }}"
 *              class="link link-hover font-medium"
 *              data-row-link-target="link"
 *              data-turbo-frame="_top"
 *              aria-label="Open …">{{ row.label }}</a>
 *       </td>
 *       …
 *   </tr>
 *
 * - The `link` target is the row's single source of truth for the destination.
 *   It MUST be a real `<a href>`: that is what makes the row keyboard
 *   reachable, middle-clickable, "copy link address"-able and announced as a
 *   link by screen readers. Navigation here is performed by clicking that
 *   anchor, so its own attributes (`data-turbo-frame`, `target`, `download`, …)
 *   keep working untouched.
 * - Clicks that land on another interactive element (`a`, `button`, `input`,
 *   `select`, `textarea`, `label`, `summary`) or on anything marked
 *   `data-row-link-ignore` are left alone — secondary row actions keep working
 *   and no longer need z-index juggling.
 * - Modified clicks (ctrl/cmd/shift/alt, or any non-primary button) fall
 *   through to the browser so "open in new tab/window" behaves natively.
 * - An active text selection suppresses navigation, so selecting a value out of
 *   a row does not yank the user to another page.
 * - Rows without a `link` target are inert; the controller can be attached
 *   unconditionally.
 */
const INTERACTIVE_SELECTOR = 'a, button, input, select, textarea, label, summary, [data-row-link-ignore]';

export default class extends Controller {
    static targets = ['link'];

    navigate(event) {
        if (!this.hasLinkTarget) {
            return;
        }

        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const origin = event.target instanceof Element ? event.target : null;
        if (origin !== null && origin.closest(INTERACTIVE_SELECTOR) !== null) {
            return;
        }

        const selection = window.getSelection();
        if (selection !== null && selection.toString() !== '') {
            return;
        }

        this.linkTarget.click();
    }
}

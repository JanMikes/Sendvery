import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/*
 * Upgrades a native <select> (single or multiple) into a searchable TomSelect
 * combobox. Styling lives in assets/styles/app.css under "TomSelect" — it is
 * driven entirely by daisyUI theme tokens so the widget is indistinguishable
 * from the app's other input/select controls.
 *
 * Progressive enhancement by design: the original <select> stays in the DOM
 * with its name, options and selected state, so a GET form keeps submitting
 * `domain[]=…&reporter[]=…` and the page still works without JavaScript.
 *
 * Values:
 *   placeholder    String  — hint shown while nothing is selected.
 *   allowCreate    Boolean — let the user invent values that are not in the
 *                            option list. Default false; keep it off whenever
 *                            the options come from the database, because a
 *                            made-up value can never match a stored row.
 *   maxItems       Number  — cap on how many items may be selected; 0 means
 *                            unlimited (default). A cap of 1 renders as a
 *                            single-choice combobox.
 *   searchable     Boolean — allow type-to-filter (default true).
 *   size           String  — 'xs' | 'sm' | 'md' | 'lg', matching the daisyUI
 *                            input scale (default 'md').
 *   submitOnChange Boolean — submit the owning form whenever the selection
 *                            changes, debounced. Default false so pages with
 *                            an explicit submit button keep it as the only
 *                            trigger.
 *
 * Targets: none — the controller element IS the <select>.
 */

// daisyUI's form-widget skins style the native control. TomSelect copies the
// <select>'s classes onto its wrapper <div>, where `select` / `select-bordered`
// / `input-sm` would fight our own `.ts-*` rules (fixed height, a second caret
// arrow). We take them off for the lifetime of the widget and hand them back on
// teardown, so the no-JS fallback keeps looking like a daisyUI control. Layout
// classes (`w-full`, `flex-1`, …) are left alone and carry over to the wrapper.
const SKIN_CLASS_PATTERN = /^(select|input|textarea|file-input)(-.+)?$/;

const SIZE_CLASSES = {
    xs: 'ts-size-xs',
    sm: 'ts-size-sm',
    md: 'ts-size-md',
    lg: 'ts-size-lg',
};

const SUBMIT_DEBOUNCE_MS = 350;

export default class extends Controller {
    static values = {
        placeholder: String,
        allowCreate: { type: Boolean, default: false },
        maxItems: { type: Number, default: 0 },
        searchable: { type: Boolean, default: true },
        size: { type: String, default: 'md' },
        submitOnChange: { type: Boolean, default: false },
    };

    connect() {
        this.tomSelect = null;
        this.originalClassName = null;
        this.submitTimer = null;

        // Turbo Drive snapshots the live DOM before it leaves a page. Without
        // this the snapshot would contain the generated wrapper, and a back
        // navigation would re-run connect() on top of it — two widgets for one
        // select. Tearing down here means the cached page holds the clean,
        // server-rendered <select>.
        this.beforeCacheHandler = () => this.teardown();
        document.addEventListener('turbo:before-cache', this.beforeCacheHandler);

        this.buildWidget();
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCacheHandler);
        this.teardown();
    }

    // Not named initialize()/setup() on purpose — those collide with the
    // Stimulus and TomSelect lifecycles respectively.
    buildWidget() {
        if (this.element.tomselect) {
            return;
        }

        this.originalClassName = this.element.className;
        this.element.className = this.layoutClasses();

        const settings = {
            plugins: this.element.multiple ? ['remove_button'] : [],
            create: this.allowCreateValue,
            allowEmptyOption: false,
            openOnFocus: true,
            selectOnTab: true,
            // In multi mode TomSelect keeps the placeholder next to the chips,
            // where "All domains" contradicts the two domains already picked.
            hidePlaceholder: true,
            onChange: () => this.scheduleSubmit(),
        };

        // Only set the keys we were actually asked to change. TomSelect derives
        // maxItems from the element (1 unless it is `multiple`) and explicit
        // settings always win, so a blanket `maxItems: null` would silently turn
        // every single-choice select into a multi-picker.
        if (this.maxItemsValue > 0) {
            settings.maxItems = this.maxItemsValue;
        }

        if ('' !== this.placeholderValue) {
            settings.placeholder = this.placeholderValue;
        }

        if (!this.searchableValue) {
            settings.controlInput = null;
        }

        this.tomSelect = new TomSelect(this.element, settings);

        const sizeClass = SIZE_CLASSES[this.sizeValue] ?? SIZE_CLASSES.md;
        this.tomSelect.wrapper.classList.add(sizeClass);
        this.tomSelect.dropdown.classList.add(sizeClass);
    }

    teardown() {
        if (null !== this.submitTimer) {
            clearTimeout(this.submitTimer);
            this.submitTimer = null;
        }

        if (null !== this.tomSelect) {
            this.tomSelect.destroy();
            this.tomSelect = null;
        }

        // destroy() puts back innerHTML and tabIndex but not the class list.
        if (null !== this.originalClassName) {
            this.element.className = this.originalClassName;
            this.originalClassName = null;
        }
    }

    scheduleSubmit() {
        if (!this.submitOnChangeValue) {
            return;
        }

        const form = this.element.form;

        if (!form) {
            return;
        }

        // Debounced so picking three domains in a row is one navigation, not
        // three. requestSubmit() goes through the form's submit event, which is
        // what lets Turbo Drive turn it into an `advance` visit.
        if (null !== this.submitTimer) {
            clearTimeout(this.submitTimer);
        }

        this.submitTimer = setTimeout(() => {
            this.submitTimer = null;

            if ('function' === typeof form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }, SUBMIT_DEBOUNCE_MS);
    }

    layoutClasses() {
        return this.originalClassName
            .split(/\s+/)
            .filter((name) => '' !== name && !SKIN_CLASS_PATTERN.test(name))
            .join(' ');
    }
}

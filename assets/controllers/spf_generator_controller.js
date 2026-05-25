import { Controller } from '@hotwired/stimulus';

/*
 * Builds a valid SPF TXT record from checkbox provider picks + freeform
 * mechanisms + an `all` terminator radio. All output assignments use
 * textContent (never innerHTML) — the freeform textarea is user input and
 * must not be parsed as HTML.
 *
 * Values:
 *   providers — JSON array of { key, label, include } published by
 *               SpfProviderRegistry::allAsJson() so the controller can
 *               translate checkbox keys to `include:` mechanisms.
 *
 * Targets:
 *   output     — <code> element that holds the rendered record.
 *   terminator — the `~all` / `-all` radio inputs (default ~all).
 *   freeform   — extra mechanisms textarea (e.g. "ip4:1.2.3.4").
 *   checkbox   — one per provider (data-spf-provider-key on each).
 *   panel      — hidden output wrapper, revealed on first generate().
 */
export default class extends Controller {
    static values = {
        providers: Array,
    };

    // TASK-153: copyButton target was declared but never wired in any
    // template — the copy() action uses event.currentTarget. Dead code
    // removed so anyone extending the controller doesn't waste time
    // looking for the non-existent binding.
    static targets = ['output', 'terminator', 'freeform', 'checkbox', 'panel'];

    generate() {
        const includesByKey = {};
        for (const provider of this.providersValue) {
            includesByKey[provider.key] = provider.include;
        }

        const includes = [];
        for (const checkbox of this.checkboxTargets) {
            if (checkbox.checked && includesByKey[checkbox.dataset.spfProviderKey]) {
                includes.push('include:' + includesByKey[checkbox.dataset.spfProviderKey]);
            }
        }

        const freeform = this.hasFreeformTarget ? this.freeformTarget.value.trim() : '';

        let terminator = '~all';
        for (const radio of this.terminatorTargets) {
            if (radio.checked) {
                terminator = radio.value;
                break;
            }
        }

        const parts = ['v=spf1', ...includes];
        if (freeform.length > 0) {
            // Split freeform on whitespace so users can paste multi-token additions.
            parts.push(...freeform.split(/\s+/).filter(Boolean));
        }
        parts.push(terminator);

        const result = parts.join(' ');
        this.outputTarget.textContent = result;

        if (this.hasPanelTarget) {
            this.panelTarget.hidden = false;
        }
    }

    async copy(event) {
        try {
            await navigator.clipboard.writeText(this.outputTarget.textContent);
            this.flashCopied(event.currentTarget);
        } catch (e) {
            /* clipboard API blocked — silent fail */
        }
    }

    flashCopied(button) {
        const previous = button.dataset.originalLabel || button.textContent;
        button.dataset.originalLabel = previous;
        button.textContent = 'Copied!';
        window.setTimeout(() => {
            button.textContent = previous;
        }, 1500);
    }
}

import { Controller } from '@hotwired/stimulus';

/*
 * Renders MX record sets for a chosen mailbox provider preset. Only the
 * Microsoft 365 preset needs custom input (the tenant slug — e.g. "acme"
 * becomes "acme.mail.protection.outlook.com"). All other presets are
 * static lookups in the JSON registry published by MxPresetRegistry.
 *
 * Output uses textContent (the tenant input is user-supplied). Multiple
 * records are joined with newlines and rendered in a <pre><code> block.
 *
 * Values:
 *   presets — JSON array of { key, label, records: [{ priority, host }] }.
 *
 * Targets:
 *   output, copyButton, panel — same shape as the SPF generator.
 *   preset                    — <select> with one <option> per preset key.
 *   tenant                    — <input> for the Microsoft tenant slug.
 *   tenantPanel               — wrapper hidden unless preset === 'microsoft'.
 */
export default class extends Controller {
    static values = {
        presets: Array,
    };

    // TASK-153: copyButton target removed (was declared but never wired in
    // the template — copy() uses event.currentTarget).
    static targets = ['output', 'panel', 'preset', 'tenant', 'tenantPanel'];

    presetChanged() {
        if (!this.hasPresetTarget) {
            return;
        }

        const key = this.presetTarget.value;
        const preset = this.presetsValue.find((entry) => entry.key === key);

        if (this.hasTenantPanelTarget) {
            this.tenantPanelTarget.hidden = key !== 'microsoft';
        }

        if (!preset) {
            this.outputTarget.textContent = '';
            return;
        }

        const tenant = this.hasTenantTarget ? this.tenantTarget.value.trim() : '';
        const lines = preset.records.map((record) => {
            let host = record.host;
            if (key === 'microsoft' && tenant.length > 0) {
                host = tenant + '.mail.protection.outlook.com';
            }
            return record.priority + ' ' + host;
        });

        this.outputTarget.textContent = lines.join('\n');

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

import { Controller } from '@hotwired/stimulus';

/*
 * Builds a DKIM TXT record from a selector, domain, key type (rsa/ed25519),
 * and raw PEM-encoded public key. Strips PEM armor + whitespace, then
 * chunks the resulting `v=DKIM1; k=...; p=...` value into 255-character
 * substrings wrapped in quotes so it parses as a valid TXT record per
 * RFC 1035. Everything is rendered via textContent — the raw key field
 * is user input.
 *
 * Targets:
 *   output, copyButton, panel — same shape as the SPF generator.
 *   selector                  — <input> for the DKIM selector.
 *   domain                    — <input> for the domain.
 *   keyType                   — <select> with rsa/ed25519.
 *   rawKey                    — <textarea> for the PEM public key.
 *   hostLabel                 — <code> shown above the record output
 *                                rendering "<selector>._domainkey.<domain>".
 */
export default class extends Controller {
    // TASK-153: copyButton target removed (was declared but never wired in
    // the template — copy() uses event.currentTarget).
    static targets = [
        'output', 'panel',
        'selector', 'domain', 'keyType', 'rawKey', 'hostLabel',
    ];

    generate() {
        const selector = this.hasSelectorTarget ? this.selectorTarget.value.trim() : '';
        const domain = this.hasDomainTarget ? this.domainTarget.value.trim() : '';
        const keyType = this.hasKeyTypeTarget ? this.keyTypeTarget.value.trim() : 'rsa';
        const rawKey = this.hasRawKeyTarget ? this.rawKeyTarget.value : '';

        const cleanedKey = this.stripPem(rawKey);

        const value = 'v=DKIM1; k=' + keyType + '; p=' + cleanedKey;
        const chunks = this.chunk(value, 255).map((piece) => '"' + piece + '"').join(' ');

        this.outputTarget.textContent = chunks;

        if (this.hasHostLabelTarget) {
            const safeSelector = selector.length > 0 ? selector : '<selector>';
            const safeDomain = domain.length > 0 ? domain : '<domain>';
            this.hostLabelTarget.textContent = safeSelector + '._domainkey.' + safeDomain;
        }

        if (this.hasPanelTarget) {
            this.panelTarget.hidden = false;
        }
    }

    /**
     * Remove PEM header/footer and every whitespace char (including
     * embedded newlines inside the base64 body). Anything that survives is
     * the raw base64 key data we want to publish as the `p=` value.
     */
    stripPem(raw) {
        return raw
            .replace(/-----BEGIN [^-]+-----/g, '')
            .replace(/-----END [^-]+-----/g, '')
            .replace(/\s+/g, '');
    }

    chunk(s, n) {
        const out = [];
        for (let i = 0; i < s.length; i += n) {
            out.push(s.slice(i, i + n));
        }
        return out;
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

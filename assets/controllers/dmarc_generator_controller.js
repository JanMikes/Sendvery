import { Controller } from '@hotwired/stimulus';

/*
 * Builds a DMARC TXT record (intended for _dmarc.<domain>) from policy /
 * subdomain-policy / pct / rua / ruf / alignment inputs. Email lists in
 * the rua/ruf inputs are comma-separated and get a `mailto:` prefix per
 * entry. All output uses textContent — user-supplied email addresses must
 * never be rendered as HTML.
 *
 * Targets:
 *   output, panel             — same shape as the SPF generator.
 *   policy, subdomainPolicy   — <select> for p= and sp=.
 *   pct                       — <input type="number"> for pct=.
 *   rua, ruf                  — comma-separated email <input>.
 *   adkim, aspf               — <select> for adkim=/aspf= (r/s).
 *
 * TASK-153: copyButton target removed (was declared but never wired in
 *           the template — copy() uses event.currentTarget).
 * TASK-154: rua/ruf inputs are validated against a minimal email-shape
 *           regex BEFORE entries get the `mailto:` prefix. Malformed
 *           entries are skipped from the generated record AND visually
 *           flagged on the corresponding input (aria-invalid + ring).
 * TASK-157: adkim=/aspf= tags are OMITTED when both are at the RFC 7489
 *           default ("r" / relaxed) — the record stays concise. Emitted
 *           when either is set to "s" so the reader sees the full intent.
 */
const EMAIL_SHAPE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

export default class extends Controller {
    static targets = [
        'output', 'panel',
        'policy', 'subdomainPolicy', 'pct',
        'rua', 'ruf', 'adkim', 'aspf',
    ];

    generate() {
        const parts = ['v=DMARC1'];

        const policy = this.hasPolicyTarget ? this.policyTarget.value.trim() : '';
        if (policy.length > 0) {
            parts.push('p=' + policy);
        }

        const subdomainPolicy = this.hasSubdomainPolicyTarget ? this.subdomainPolicyTarget.value.trim() : '';
        if (subdomainPolicy.length > 0) {
            parts.push('sp=' + subdomainPolicy);
        }

        const ruaList = this.buildMailtoList(this.hasRuaTarget ? this.ruaTarget : null);
        if (ruaList.length > 0) {
            parts.push('rua=' + ruaList.join(','));
        }

        const rufList = this.buildMailtoList(this.hasRufTarget ? this.rufTarget : null);
        if (rufList.length > 0) {
            parts.push('ruf=' + rufList.join(','));
        }

        const pct = this.hasPctTarget ? this.pctTarget.value.trim() : '';
        if (pct.length > 0) {
            parts.push('pct=' + pct);
        }

        // TASK-157: omit adkim=/aspf= when both at the RFC 7489 default.
        // Emit BOTH when either is set to strict so the record is
        // self-documenting (reader doesn't have to remember the default).
        const adkim = this.hasAdkimTarget ? this.adkimTarget.value.trim() : 'r';
        const aspf = this.hasAspfTarget ? this.aspfTarget.value.trim() : 'r';
        const eitherStrict = adkim === 's' || aspf === 's';
        if (eitherStrict) {
            parts.push('adkim=' + adkim);
            parts.push('aspf=' + aspf);
        }

        this.outputTarget.textContent = parts.join('; ');

        if (this.hasPanelTarget) {
            this.panelTarget.hidden = false;
        }
    }

    buildMailtoList(input) {
        if (null === input) {
            return [];
        }

        // Strip any leading `mailto:` so pasting a copied DMARC tag value
        // (e.g. "mailto:reports@example.com") doesn't emit "mailto:mailto:…".
        const entries = input.value
            .split(',')
            .map((entry) => entry.trim().replace(/^mailto:/i, ''))
            .filter((entry) => entry.length > 0);

        // TASK-154: validate each entry against a minimal email-shape regex.
        // Malformed entries are excluded from the generated record so DNS
        // doesn't end up with a typo-broken `rua=mailto:reports@`. Visually
        // flag the input when ANY entry is malformed so the user knows why
        // their address didn't make it into the record.
        const valid = [];
        let anyInvalid = false;
        for (const entry of entries) {
            if (EMAIL_SHAPE.test(entry)) {
                valid.push('mailto:' + entry);
            } else {
                anyInvalid = true;
            }
        }

        if (anyInvalid) {
            input.setAttribute('aria-invalid', 'true');
            input.classList.add('ring-1', 'ring-warning');
        } else {
            input.removeAttribute('aria-invalid');
            input.classList.remove('ring-1', 'ring-warning');
        }

        return valid;
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

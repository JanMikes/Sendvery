import { Controller } from '@hotwired/stimulus';

/*
 * Builds a DMARC TXT record (intended for _dmarc.<domain>) from policy /
 * subdomain-policy / pct / rua / ruf / alignment inputs. Email lists in
 * the rua/ruf inputs are comma-separated and get a `mailto:` prefix per
 * entry. All output uses textContent — user-supplied email addresses must
 * never be rendered as HTML.
 *
 * Targets:
 *   output, copyButton, panel — same shape as the SPF generator.
 *   policy, subdomainPolicy   — <select> for p= and sp=.
 *   pct                       — <input type="number"> for pct=.
 *   rua, ruf                  — comma-separated email <input>.
 *   adkim, aspf               — <select> for adkim=/aspf= (r/s).
 */
export default class extends Controller {
    static targets = [
        'output', 'copyButton', 'panel',
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

        const ruaList = this.buildMailtoList(this.hasRuaTarget ? this.ruaTarget.value : '');
        if (ruaList.length > 0) {
            parts.push('rua=' + ruaList.join(','));
        }

        const rufList = this.buildMailtoList(this.hasRufTarget ? this.rufTarget.value : '');
        if (rufList.length > 0) {
            parts.push('ruf=' + rufList.join(','));
        }

        const pct = this.hasPctTarget ? this.pctTarget.value.trim() : '';
        if (pct.length > 0) {
            parts.push('pct=' + pct);
        }

        const adkim = this.hasAdkimTarget ? this.adkimTarget.value.trim() : '';
        if (adkim.length > 0) {
            parts.push('adkim=' + adkim);
        }

        const aspf = this.hasAspfTarget ? this.aspfTarget.value.trim() : '';
        if (aspf.length > 0) {
            parts.push('aspf=' + aspf);
        }

        this.outputTarget.textContent = parts.join('; ');

        if (this.hasPanelTarget) {
            this.panelTarget.hidden = false;
        }
    }

    buildMailtoList(raw) {
        // Strip any leading `mailto:` so pasting a copied DMARC tag value
        // (e.g. "mailto:reports@example.com") doesn't emit "mailto:mailto:…".
        return raw
            .split(',')
            .map((entry) => entry.trim())
            .filter((entry) => entry.length > 0)
            .map((entry) => 'mailto:' + entry.replace(/^mailto:/i, ''));
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

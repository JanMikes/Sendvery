import { Controller } from '@hotwired/stimulus';

/*
 * Writes a URL to the clipboard and flashes inline confirmation on the
 * button label. Used by the alert detail header — and anywhere else we
 * need a one-click "share this URL" handoff for support/Slack.
 */
export default class extends Controller {
    static values = { url: String };
    static targets = ['label'];

    async copy(event) {
        event.preventDefault();
        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.flash('Copied!');
        } catch (e) {
            this.flash('Copy failed');
        }
    }

    flash(text) {
        if (!this.hasLabelTarget) {
            return;
        }
        const previous = this.labelTarget.dataset.copyLinkDefault || this.labelTarget.textContent;
        this.labelTarget.textContent = text;
        window.setTimeout(() => {
            this.labelTarget.textContent = previous;
        }, 1500);
    }
}

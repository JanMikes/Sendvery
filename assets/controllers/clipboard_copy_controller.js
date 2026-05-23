import { Controller } from '@hotwired/stimulus';

/*
 * Writes a configurable text payload to the clipboard and flashes inline
 * confirmation on a button label target. Used by the "Self-host in 60
 * seconds" quickstart on /about/open-source so visitors can copy the
 * three commands without hand-selecting them. Same defensive try/catch
 * shape as copy_link_controller; falls back to "Copy failed" if the
 * browser denies the clipboard API.
 */
export default class extends Controller {
    static values = { text: String };
    static targets = ['label'];

    async copy(event) {
        event.preventDefault();
        try {
            await navigator.clipboard.writeText(this.textValue);
            this.flash('Copied!');
        } catch (e) {
            this.flash('Copy failed');
        }
    }

    flash(text) {
        if (!this.hasLabelTarget) {
            return;
        }
        const previous = this.labelTarget.dataset.clipboardCopyDefault || this.labelTarget.textContent;
        this.labelTarget.dataset.clipboardCopyDefault = previous;
        this.labelTarget.textContent = text;
        window.setTimeout(() => {
            this.labelTarget.textContent = previous;
        }, 1500);
    }
}

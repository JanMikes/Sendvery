import { Controller } from '@hotwired/stimulus';

// Polls the verify endpoint while we're still in the "not visible yet" state.
// Attaches to the turbo-frame itself; the frame's own src reload mechanism does
// the actual fetching. We cap attempts so we don't poll forever on an abandoned
// tab — at the cap, the user can still click "Retry now" to keep trying.
export default class extends Controller {
    static values = {
        verified: Boolean,
        url: String,
        interval: { type: Number, default: 15000 },
        maxAttempts: { type: Number, default: 20 },
    };

    static targets = ['status'];

    connect() {
        this.attempts = 0;

        if (this.verifiedValue) {
            return;
        }

        this.schedule();
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    schedule() {
        if (this.attempts >= this.maxAttemptsValue) {
            this.setStatus("we've stopped auto-retrying — click \"Retry now\" when ready.");
            return;
        }

        const secs = Math.round(this.intervalValue / 1000);
        this.setStatus(`retrying in ${secs}s…`);

        this.timer = setTimeout(() => {
            this.attempts += 1;
            this.element.src = this.urlValue;
        }, this.intervalValue);
    }

    setStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }
}

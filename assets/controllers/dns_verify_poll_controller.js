import { Controller } from '@hotwired/stimulus';

/*
 * Keeps a turbo-frame that is waiting on DNS up to date without the user
 * touching anything.
 *
 * Attaches to the turbo-frame itself, because polling works by assigning
 * `frame.src` and letting Turbo do the fetching. Turbo replaces a frame's
 * CHILDREN and keeps the element, so this controller instance survives every
 * refresh — which is exactly why `attempts` can be trusted as a hard cap, and
 * why the next tick has to be scheduled from the `turbo:frame-load` event
 * rather than assumed to happen on reconnect.
 *
 * Two independent stop conditions, because "poll forever" is never acceptable:
 *
 *  - `settledSelector` (optional): a CSS selector the frame's own markup
 *    renders once there is a real answer. This is the *good* stop — the frame
 *    tells us it is done and we go quiet immediately.
 *  - `maxAttempts`: the backstop for an abandoned tab, a stuck worker, or a
 *    frame that never reports settled. At the cap we say so and leave the
 *    manual action as the way forward, instead of hammering the server.
 */
export default class extends Controller {
    static values = {
        verified: Boolean,
        url: String,
        interval: { type: Number, default: 15000 },
        maxAttempts: { type: Number, default: 20 },
        settledSelector: { type: String, default: '' },
    };

    static targets = ['status'];

    connect() {
        this.attempts = 0;
        this.onFrameLoad = () => this.afterLoad();
        this.element.addEventListener('turbo:frame-load', this.onFrameLoad);

        if (this.settled()) {
            return;
        }

        this.schedule();
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.onFrameLoad);

        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    // Each refreshed frame either carries the settled marker — in which case we
    // stop for good — or is still pending and earns one more tick.
    afterLoad() {
        if (this.settled()) {
            this.setStatus('');
            return;
        }

        this.schedule();
    }

    settled() {
        if (this.verifiedValue) {
            return true;
        }

        if (this.settledSelectorValue === '') {
            return false;
        }

        return this.element.querySelector(this.settledSelectorValue) !== null;
    }

    schedule() {
        if (this.attempts >= this.maxAttemptsValue) {
            this.setStatus("we've stopped auto-checking — use the button when you're ready.");
            return;
        }

        const secs = Math.round(this.intervalValue / 1000);
        this.setStatus(`checking again in ${secs}s…`);

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

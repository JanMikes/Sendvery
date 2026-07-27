import { Controller } from '@hotwired/stimulus';

/*
 * Radio-driven "how should Sendvery receive your DMARC reports?" chooser.
 *
 * Both delivery paths (self-managed TXT record, managed CNAME) are rendered
 * server-side; this controller only decides which one's detail panel is on
 * screen. Progressive enhancement is deliberate: the panel matching the
 * currently-selected radio is NOT hidden in the markup, so with JavaScript off
 * the user still sees the path their domain is actually on, and both radios
 * remain real inputs inside their own forms.
 *
 * CONTRACT:
 *
 *   <div {{ stimulus_controller('delivery-choice') }}>
 *     <input type="radio" name="delivery" value="self_txt"
 *            data-action="change->delivery-choice#select">
 *     <div data-delivery-choice-target="panel" data-delivery-choice-mode="self_txt">…</div>
 *     …
 *   </div>
 *
 * Panels are matched to radios by the `value` / `data-delivery-choice-mode`
 * pair, so adding a third delivery path needs no JS change.
 */
export default class extends Controller {
    static targets = ['panel', 'option'];

    select(event) {
        this.reveal(event.target.value);
    }

    reveal(mode) {
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.deliveryChoiceMode !== mode;
        });

        this.optionTargets.forEach((option) => {
            option.dataset.deliveryChoiceSelected =
                option.dataset.deliveryChoiceMode === mode ? 'true' : 'false';
        });
    }
}

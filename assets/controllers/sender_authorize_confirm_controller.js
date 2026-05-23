import { Controller } from '@hotwired/stimulus';

/*
 * Attached to the hidden authorize-form. The per-row Authorize <button>
 * uses the HTML5 form= attribute to target this form, which fires a
 * `submit` event we can intercept. Once the user has confirmed once
 * during this browser tab session, subsequent authorizations skip the
 * confirm dialog so bulk-feeling per-row workflows don't get prompty.
 */
export default class extends Controller {
    confirmIfNeeded(event) {
        if (sessionStorage.getItem('senderAuthorizeConfirmed')) {
            return;
        }
        const confirmed = confirm(
            'Authorizing this sender means Sendvery will trust mail it sends as your domain. ' +
            'Real failures from this IP will no longer trigger alerts. Continue?'
        );
        if (!confirmed) {
            event.preventDefault();
            return;
        }
        sessionStorage.setItem('senderAuthorizeConfirmed', '1');
    }
}

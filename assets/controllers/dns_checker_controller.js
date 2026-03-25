import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'selectorInput', 'results', 'spinner', 'submitBtn', 'error'];
    static values = { url: String };

    async submit(event) {
        event.preventDefault();

        const domain = this.inputTarget.value.trim();
        if (!domain) {
            this.showError('Please enter a domain name.');
            return;
        }

        if (!this.isValidDomain(domain)) {
            this.showError('Please enter a valid domain name (e.g. example.com).');
            return;
        }

        this.showLoading();
        this.hideError();

        const params = new URLSearchParams({ domain });

        if (this.hasSelectorInputTarget && this.selectorInputTarget.value.trim()) {
            params.set('selector', this.selectorInputTarget.value.trim());
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: params.toString(),
            });

            if (!response.ok) {
                throw new Error(`Server error (${response.status})`);
            }

            const html = await response.text();
            this.resultsTarget.innerHTML = html;
            this.resultsTarget.classList.remove('hidden');
        } catch (error) {
            this.showError('Failed to check domain. Please try again.');
        } finally {
            this.hideLoading();
        }
    }

    isValidDomain(domain) {
        return /^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/.test(domain);
    }

    showLoading() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.remove('hidden');
        }
        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.disabled = true;
        }
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('hidden');
        }
    }

    hideLoading() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add('hidden');
        }
        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.disabled = false;
        }
    }

    showError(message) {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message;
            this.errorTarget.classList.remove('hidden');
        }
    }

    hideError() {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('hidden');
        }
    }
}

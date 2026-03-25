import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const saved = localStorage.getItem('theme');
        if (saved) {
            document.documentElement.setAttribute('data-theme', saved);
        }
    }

    toggle() {
        const current = document.documentElement.getAttribute('data-theme');
        const next = current === 'sendvery-dark' ? 'sendvery' : 'sendvery-dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
    }
}

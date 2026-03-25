import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['lightIcon', 'darkIcon'];

    connect() {
        const saved = localStorage.getItem('theme');
        if (saved) {
            document.documentElement.setAttribute('data-theme', saved);
        }
        this.updateIcons();
    }

    toggle() {
        const current = document.documentElement.getAttribute('data-theme');
        const next = current === 'sendvery-dark' ? 'sendvery' : 'sendvery-dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        this.updateIcons();
    }

    updateIcons() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'sendvery-dark';
        this.lightIconTargets.forEach(el => el.classList.toggle('hidden', isDark));
        this.darkIconTargets.forEach(el => el.classList.toggle('hidden', !isDark));
    }
}

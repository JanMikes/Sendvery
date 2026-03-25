import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        config: Object,
    };

    async connect() {
        const { default: ApexCharts } = await import('apexcharts');

        const theme = document.documentElement.getAttribute('data-theme');
        const isDark = theme === 'sendvery-dark';

        const defaults = {
            chart: {
                background: 'transparent',
                fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
                toolbar: { show: false },
            },
            theme: {
                mode: isDark ? 'dark' : 'light',
            },
            grid: {
                borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
            },
        };

        const config = this.deepMerge(defaults, this.configValue);
        this.chart = new ApexCharts(this.element, config);
        this.chart.render();

        this.observer = new MutationObserver(() => {
            const newTheme = document.documentElement.getAttribute('data-theme');
            const newDark = newTheme === 'sendvery-dark';
            this.chart.updateOptions({
                theme: { mode: newDark ? 'dark' : 'light' },
                grid: { borderColor: newDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' },
            });
        });
        this.observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme'],
        });
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
        }
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    deepMerge(target, source) {
        const result = { ...target };
        for (const key of Object.keys(source)) {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                result[key] = this.deepMerge(result[key] || {}, source[key]);
            } else {
                result[key] = source[key];
            }
        }
        return result;
    }
}

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        config: Object,
    };

    async connect() {
        const { default: ApexCharts } = await import('apexcharts');

        const defaults = {
            chart: {
                background: 'transparent',
                fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
                toolbar: { show: false },
            },
            theme: {
                mode: 'light',
            },
            grid: {
                borderColor: 'rgba(0,0,0,0.1)',
            },
        };

        const config = this.deepMerge(defaults, this.configValue);
        this.chart = new ApexCharts(this.element, config);
        this.chart.render();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
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

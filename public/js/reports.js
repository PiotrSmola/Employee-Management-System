/*
 * Draws every <canvas data-chart="..."> of the report pages with Chart.js.
 * Series colours are fixed palette slots (App\Reports\ChartSeries), stepped separately for
 * light and dark mode; the charts are recoloured when the theme toggle changes data-bs-theme.
 */
(function () {
    if (typeof Chart === 'undefined') {
        return;
    }

    const themes = {
        light: {
            series: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
            other: '#898781',
            text: '#52514e',
            grid: '#e1e0d9',
            axis: '#c3c2b7',
            surface: '#ffffff',
        },
        dark: {
            series: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
            other: '#898781',
            text: '#c3c2b7',
            grid: '#2c2c2a',
            axis: '#383835',
            surface: '#212529',
        },
    };
    const locale = document.documentElement.lang || undefined;
    const formats = {
        int: (v) => Number(v).toLocaleString(locale, {maximumFractionDigits: 0}),
        money: (v) => '$' + Number(v).toLocaleString(locale, {maximumFractionDigits: 0}),
        percent: (v) => Number(v).toLocaleString(locale, {maximumFractionDigits: 1}) + '%',
        years: (v) => Number(v).toLocaleString(locale, {maximumFractionDigits: 1}),
    };

    const theme = () => document.documentElement.getAttribute('data-bs-theme') === 'dark' ? themes.dark : themes.light;
    const colour = (t, slot) => slot === 'other' ? t.other : t.series[Number(slot) % t.series.length];

    function datasets(spec, t) {
        return spec.datasets.map((d) => {
            const isLine = spec.type === 'line';
            const background = d.slots ? d.slots.map((s) => colour(t, s)) : colour(t, d.slot);

            return {
                label: d.label,
                data: d.data,
                backgroundColor: background,
                borderColor: spec.type === 'doughnut' ? t.surface : background,
                borderWidth: spec.type === 'doughnut' ? 2 : (isLine ? 2 : 0),
                borderRadius: spec.type === 'bar' ? 4 : 0,
                borderSkipped: spec.stacked ? false : 'start',
                pointRadius: isLine ? 3 : undefined,
                pointHoverRadius: isLine ? 6 : undefined,
                tension: isLine ? 0.25 : undefined,
                spanGaps: true,
                maxBarThickness: 48,
            };
        });
    }

    function options(spec, t) {
        const format = formats[spec.format] || formats.int;
        const showLegend = spec.datasets.length > 1 || spec.type === 'doughnut';
        const tooltip = {
            callbacks: {
                label: (ctx) => {
                    const value = spec.type === 'doughnut' ? ctx.parsed : (spec.horizontal ? ctx.parsed.x : ctx.parsed.y);
                    const name = spec.type === 'doughnut' ? ctx.label : ctx.dataset.label;
                    return name + ': ' + (value === null ? '–' : format(value));
                },
            },
        };

        if (spec.type === 'doughnut') {
            return {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {legend: {position: 'bottom', labels: {color: t.text, usePointStyle: true}}, tooltip},
            };
        }

        const valueAxis = {
            stacked: !!spec.stacked,
            beginAtZero: spec.type === 'bar' || spec.format !== 'money',
            grid: {color: t.grid},
            border: {color: t.axis},
            ticks: {color: t.text, callback: (v) => format(v)},
        };
        const categoryAxis = {
            stacked: !!spec.stacked,
            grid: {display: false},
            border: {color: t.axis},
            ticks: {color: t.text, autoSkip: !spec.horizontal},
        };

        return {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: spec.horizontal ? 'y' : 'x',
            interaction: {mode: 'index', intersect: false},
            scales: spec.horizontal ? {x: valueAxis, y: categoryAxis} : {x: categoryAxis, y: valueAxis},
            plugins: {
                legend: {display: showLegend, position: 'bottom', labels: {color: t.text, usePointStyle: true, boxWidth: 8}},
                tooltip,
            },
        };
    }

    const charts = [];
    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const spec = JSON.parse(canvas.dataset.chart);
        const t = theme();
        charts.push({
            spec,
            chart: new Chart(canvas, {type: spec.type, data: {labels: spec.labels, datasets: datasets(spec, t)}, options: options(spec, t)}),
        });
    });

    new MutationObserver(() => {
        const t = theme();
        charts.forEach(({spec, chart}) => {
            chart.data.datasets = datasets(spec, t);
            chart.options = options(spec, t);
            chart.update('none');
        });
    }).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});
})();

/* Lightweight Chart.js helper used by analytics pages */
if (typeof window === 'undefined') {
    return;
}

window.SFMSCharts = {
    _charts: new WeakMap(),
    renderLineChart: function (ctx, labels, data, label) {
        if (!window.Chart || !ctx) return null;
        // destroy previous chart on this canvas
        try { const prev = this._charts.get(ctx.canvas); if (prev && typeof prev.destroy === 'function') prev.destroy(); } catch (e) {}
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: label || '',
                    data: data,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { display: true },
                    y: { beginAtZero: true }
                }
            }
        });
        try { this._charts.set(ctx.canvas, chart); } catch (e) {}
        return chart;
    }
    , renderBarChart: function (ctx, labels, data, label) {
        if (!window.Chart || !ctx) return null;
        try { const prev = this._charts.get(ctx.canvas); if (prev && typeof prev.destroy === 'function') prev.destroy(); } catch (e) {}
        const chart = new Chart(ctx, {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: label || '', data: data, backgroundColor: '#60a5fa' }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { display: true }, y: { beginAtZero: true } } }
        });
        try { this._charts.set(ctx.canvas, chart); } catch (e) {}
        return chart;
    }
    , renderDoughnutChart: function (ctx, labels, data, label) {
        if (!window.Chart || !ctx) return null;
        try { const prev = this._charts.get(ctx.canvas); if (prev && typeof prev.destroy === 'function') prev.destroy(); } catch (e) {}
        const chart = new Chart(ctx, { type: 'doughnut', data: { labels: labels, datasets: [{ label: label || '', data: data, backgroundColor: ['#34d399','#f97316','#ef4444','#60a5fa'] }] }, options: { responsive: true, maintainAspectRatio: false } });
        try { this._charts.set(ctx.canvas, chart); } catch (e) {}
        return chart;
    }
    , showSkeleton: function (el, height) {
        el.innerHTML = '<div class="ui-skeleton-list" style="height:' + (height||150) + 'px;"></div>';
    }
};

/**
 * Dashboard overview cards — shared by the Head (maintenance-dashboard.php)
 * and Staff (staff-dashboard.php) dashboards; styled by
 * assets/css/dashboard-overview.css.
 *
 * The rings are drawn as SVG rather than through chart-lite.js. chart-lite
 * (shared by every dashboard, so deliberately left untouched) always paints
 * each slice's count onto the ring, draws a dark navy ring when there is no
 * data (wrong in light mode) and leaves a seam when a single slice fills the
 * whole ring. SVG + CSS gives a clean light track for empty data, real theme
 * colors, and hover/click per segment.
 */
(function (global) {
    const DONUT_RADIUS = 48;
    const DONUT_CIRCUMFERENCE = 2 * Math.PI * DONUT_RADIUS;
    const SVG_NS = 'http://www.w3.org/2000/svg';

    function setText(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    /**
     * Draws segments into #containerId's .hd-donut-segments group.
     * segments: [{ key, label, value, href }] — key picks the .hd-stroke-<key>
     * color class; href is where a click on that segment goes.
     */
    function renderDonut(containerId, segments) {
        const container = document.getElementById(containerId);
        const group = container ? container.querySelector('.hd-donut-segments') : null;
        if (!group) {
            return;
        }

        const total = segments.reduce((sum, segment) => sum + segment.value, 0);
        const visible = segments.filter((segment) => segment.value > 0);
        // A small gap between segments only when there is more than one.
        const gap = visible.length > 1 ? 2.2 : 0;
        let offset = 0;

        group.innerHTML = '';
        visible.forEach((segment) => {
            const length = (segment.value / total) * DONUT_CIRCUMFERENCE;
            const circle = document.createElementNS(SVG_NS, 'circle');
            circle.setAttribute('class', `hd-donut-seg hd-stroke-${segment.key}`);
            circle.setAttribute('cx', '60');
            circle.setAttribute('cy', '60');
            circle.setAttribute('r', String(DONUT_RADIUS));
            circle.setAttribute('stroke-dasharray', `${Math.max(length - gap, 0.01)} ${DONUT_CIRCUMFERENCE}`);
            circle.setAttribute('stroke-dashoffset', String(-offset));
            circle.setAttribute('transform', 'rotate(-90 60 60)');

            const title = document.createElementNS(SVG_NS, 'title');
            const percent = Math.round((segment.value / total) * 100);
            title.textContent = `${segment.label}: ${segment.value} report${segment.value !== 1 ? 's' : ''} (${percent}%)`;
            circle.appendChild(title);

            circle.addEventListener('click', () => {
                window.location.href = segment.href;
            });

            group.appendChild(circle);
            offset += length;
        });

        container.classList.toggle('hd-donut-empty', total === 0);
        const summary = visible.map((segment) => `${segment.label} ${segment.value}`).join(', ');
        container.setAttribute('aria-label', total === 0 ? 'No reports' : `${total} reports: ${summary}`);
    }

    // Fills one breakdown row: count (#idPrefix), share of the total
    // (#idPrefix-pct) and bar width (#idPrefix-bar).
    function setBreakdownRow(idPrefix, count, total) {
        const percent = total > 0 ? Math.round((count / total) * 100) : 0;
        setText(idPrefix, count);
        setText(`${idPrefix}-pct`, `${percent}%`);
        const bar = document.getElementById(`${idPrefix}-bar`);
        if (bar) {
            bar.style.width = `${percent}%`;
        }
    }

    global.renderDonut = renderDonut;
    global.setBreakdownRow = setBreakdownRow;
})(window);

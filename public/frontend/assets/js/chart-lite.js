(function (global) {
    if (global.Chart) {
        return;
    }

    const DEFAULT_TEXT = '#94a3b8';

    function toNumber(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function parseCutout(cutout, outerRadius) {
        if (typeof cutout === 'number') {
            return clamp(cutout, 0, outerRadius);
        }

        if (typeof cutout === 'string' && cutout.trim().endsWith('%')) {
            const ratio = parseFloat(cutout) / 100;
            return clamp(outerRadius * (Number.isFinite(ratio) ? ratio : 0), 0, outerRadius);
        }

        return outerRadius * 0.65;
    }

    function getColor(value, index, fallback) {
        if (Array.isArray(value)) {
            return value[index % value.length] || fallback;
        }

        return value || fallback;
    }

    function resolveFontSize(fontConfig, fallback) {
        const size = Number(fontConfig && fontConfig.size);
        return Number.isFinite(size) && size > 0 ? size : fallback;
    }

    function resolveFontWeight(fontConfig, fallback) {
        const weight = fontConfig && fontConfig.weight;
        return weight ? String(weight) : fallback;
    }

    function resolveFontFamily(fontConfig, fallback) {
        const family = fontConfig && fontConfig.family;
        return family ? String(family) : fallback;
    }

    function setCanvasSize(canvas, ctx) {
        const rect = canvas.getBoundingClientRect();
        const dpr = global.devicePixelRatio || 1;
        const width = Math.max(1, Math.floor(rect.width || canvas.clientWidth || 300));
        const height = Math.max(1, Math.floor(rect.height || canvas.clientHeight || 150));

        if (canvas.width !== Math.floor(width * dpr) || canvas.height !== Math.floor(height * dpr)) {
            canvas.width = Math.floor(width * dpr);
            canvas.height = Math.floor(height * dpr);
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        return { width, height, dpr };
    }

    function formatLabel(value) {
        return String(value || '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (char) => char.toUpperCase());
    }

    function roundRectPath(ctx, x, y, width, height, radius) {
        const w = Math.max(0, width);
        const h = Math.max(0, height);
        const r = Math.max(0, Math.min(radius || 0, w / 2, h / 2));

        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h - r);
        ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        ctx.lineTo(x + r, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    function normalizeAngle(angle) {
        const full = Math.PI * 2;
        let normalized = angle % full;
        if (normalized < 0) {
            normalized += full;
        }
        return normalized;
    }

    function isAngleBetween(angle, start, end) {
        if (start <= end) {
            return angle >= start && angle <= end;
        }

        return angle >= start || angle <= end;
    }

    class ChartLite {
        constructor(ctxOrCanvas, config) {
            this.canvas = ctxOrCanvas && ctxOrCanvas.canvas ? ctxOrCanvas.canvas : ctxOrCanvas;
            this.ctx = ctxOrCanvas && ctxOrCanvas.canvas ? ctxOrCanvas : this.canvas.getContext('2d');
            this.config = config || {};
            this.type = this.config.type || 'bar';
            this.data = this.config.data || { labels: [], datasets: [] };
            this.options = this.config.options || {};
            this.plugins = Array.isArray(this.config.plugins) ? this.config.plugins.filter(Boolean) : [];
            this._destroyed = false;
            this._lastDoughnutCenter = null;
            this._hitRegions = [];

            this._resizeHandler = () => this.render();
            this._clickHandler = (event) => this._handleCanvasClick(event);
            global.addEventListener('resize', this._resizeHandler);
            this.canvas.addEventListener('click', this._clickHandler);
            this.render();
        }

        destroy() {
            if (this._destroyed) {
                return;
            }

            this._destroyed = true;
            global.removeEventListener('resize', this._resizeHandler);
            this.canvas.removeEventListener('click', this._clickHandler);
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        }

        update() {
            if (!this._destroyed) {
                this.render();
            }
        }

        getDatasetMeta(index) {
            const dataset = (this.data.datasets || [])[index] || { data: [] };
            const rect = this.canvas.getBoundingClientRect();
            const width = rect.width || this.canvas.clientWidth || 300;
            const height = rect.height || this.canvas.clientHeight || 150;
            const centerX = width / 2;
            const centerY = height / 2;

            if (this.type === 'doughnut') {
                const center = this._lastDoughnutCenter || { x: centerX, y: centerY };
                return {
                    data: [{ x: center.x, y: center.y }],
                    total: (dataset.data || []).reduce((sum, value) => sum + toNumber(value), 0)
                };
            }

            return { data: [] };
        }

        getDataVisibility() {
            return true;
        }

        render() {
            if (this._destroyed) {
                return;
            }

            const { width, height } = setCanvasSize(this.canvas, this.ctx);
            this._hitRegions = [];
            this._callPlugins('beforeDraw');

            if (this.type === 'doughnut') {
                this._drawDoughnut(width, height);
            } else if (this.type === 'line') {
                this._drawLine(width, height);
            } else {
                this._drawBar(width, height);
            }

            this._callPlugins('afterDatasetsDraw');
        }

        _handleCanvasClick(event) {
            const onClick = this.options && typeof this.options.onClick === 'function'
                ? this.options.onClick
                : null;

            if (!onClick || !this._hitRegions.length) {
                return;
            }

            const rect = this.canvas.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;

            let hitItem = null;
            for (let i = this._hitRegions.length - 1; i >= 0; i -= 1) {
                const region = this._hitRegions[i];
                if (region.type === 'arc') {
                    const dx = x - region.cx;
                    const dy = y - region.cy;
                    const distance = Math.sqrt((dx * dx) + (dy * dy));
                    if (distance < region.innerRadius || distance > region.outerRadius) {
                        continue;
                    }

                    const angle = normalizeAngle(Math.atan2(dy, dx));
                    if (!isAngleBetween(angle, region.startAngle, region.endAngle)) {
                        continue;
                    }

                    hitItem = { index: region.index, datasetIndex: 0 };
                    break;
                }

                if (region.type === 'rect') {
                    if (x >= region.x && x <= region.x + region.width && y >= region.y && y <= region.y + region.height) {
                        hitItem = { index: region.index, datasetIndex: 0 };
                        break;
                    }
                }
            }

            if (hitItem) {
                onClick(event, [hitItem]);
            }
        }

        _callPlugins(hookName) {
            for (const plugin of this.plugins) {
                if (plugin && typeof plugin[hookName] === 'function') {
                    try {
                        plugin[hookName](this);
                    } catch (error) {
                        console.error('ChartLite plugin error:', error);
                    }
                }
            }
        }

        _drawLegend(items, startX, startY, lineHeight, colorGetter) {
            const ctx = this.ctx;
            const legendLabels = this.options.plugins && this.options.plugins.legend && this.options.plugins.legend.labels
                ? this.options.plugins.legend.labels
                : {};
            const mutedText = legendLabels.color || DEFAULT_TEXT;
            const fontConfig = legendLabels.font || {};
            const fontSize = resolveFontSize(fontConfig, 12);
            const fontWeight = resolveFontWeight(fontConfig, '500');
            const fontFamily = resolveFontFamily(fontConfig, 'Segoe UI, Arial, sans-serif');
            const markerRadius = Math.max(4, Math.round(fontSize * 0.35));

            ctx.save();
            ctx.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
            ctx.textBaseline = 'middle';

            items.forEach((item, index) => {
                const y = startY + (index * lineHeight);
                ctx.fillStyle = colorGetter(item, index);
                ctx.beginPath();
                ctx.arc(startX, y, markerRadius, 0, Math.PI * 2);
                ctx.fill();

                ctx.fillStyle = mutedText || DEFAULT_TEXT;
                ctx.fillText(formatLabel(item.label), startX + (markerRadius * 2) + 4, y);
            });

            ctx.restore();
        }

        _drawDoughnut(width, height) {
            const ctx = this.ctx;
            const dataset = (this.data.datasets || [])[0] || { data: [] };
            const labels = this.data.labels || [];
            const values = dataset.data || [];
            const colors = dataset.backgroundColor || [];
            const total = values.reduce((sum, value) => sum + toNumber(value), 0);
            const legendPosition = this.options?.plugins?.legend?.position || 'right';
            const reserveLegend = legendPosition === 'right' && width >= 360;
            const legendWidth = reserveLegend ? Math.min(150, Math.max(110, width * 0.28)) : 0;
            const chartWidth = width - legendWidth;
            const radiusScaleRaw = Number(this.options?.radiusScale);
            const radiusScale = Number.isFinite(radiusScaleRaw) ? clamp(radiusScaleRaw, 0.75, 1.35) : 1;
            const radius = Math.min(chartWidth, height) * 0.34 * radiusScale;
            const maxOuterRadius = Math.max(20, Math.min((chartWidth / 2) - 20, (height / 2) - 10));
            const outerRadius = clamp(radius, 20, maxOuterRadius);
            const cutout = parseCutout(this.options.cutout, outerRadius);
            const cx = Math.max(outerRadius + 20, chartWidth / 2);
            const cy = height / 2;
            this._lastDoughnutCenter = { x: cx, y: cy };

            ctx.save();
            ctx.lineWidth = dataset.borderWidth || 0;

            if (total <= 0) {
                ctx.strokeStyle = '#334155';
                ctx.fillStyle = '#1e293b';
                ctx.beginPath();
                ctx.arc(cx, cy, outerRadius, 0, Math.PI * 2);
                ctx.arc(cx, cy, cutout, 0, Math.PI * 2, true);
                ctx.fill('evenodd');
                ctx.stroke();
            } else {
                let startAngle = -Math.PI / 2;

                values.forEach((value, index) => {
                    const count = toNumber(value);
                    if (count <= 0) {
                        return;
                    }

                    const angle = (count / total) * Math.PI * 2;
                    const endAngle = startAngle + angle;
                    const startNormalized = normalizeAngle(startAngle);
                    const endNormalized = normalizeAngle(endAngle);

                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.fillStyle = getColor(colors, index, '#64748b');
                    ctx.arc(cx, cy, outerRadius, startAngle, endAngle);
                    ctx.arc(cx, cy, cutout, endAngle, startAngle, true);
                    ctx.closePath();
                    ctx.fill();

                    if (dataset.borderWidth) {
                        ctx.strokeStyle = this.options?.cutout === '100%' ? 'transparent' : (dataset.borderColor || '#0f172a');
                        ctx.stroke();
                    }

                    const midAngle = startAngle + (angle / 2);
                    const labelRadius = (outerRadius + cutout) / 2;
                    const labelX = cx + (Math.cos(midAngle) * labelRadius);
                    const labelY = cy + (Math.sin(midAngle) * labelRadius);

                    ctx.save();
                    ctx.fillStyle = '#e2e8f0';
                    ctx.font = '700 13px "Segoe UI", sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(String(count), labelX, labelY);
                    ctx.restore();

                    this._hitRegions.push({
                        type: 'arc',
                        index,
                        cx,
                        cy,
                        innerRadius: cutout,
                        outerRadius,
                        startAngle: startNormalized,
                        endAngle: endNormalized
                    });

                    startAngle = endAngle;
                });
            }

            ctx.restore();

            if (reserveLegend) {
                const items = labels.map((label, index) => ({ label, color: getColor(colors, index, '#64748b') }));
                this._drawLegend(items, chartWidth + 18, Math.max(24, height * 0.28), 22, (item) => item.color);
            }
        }

        _drawBar(width, height) {
            const ctx = this.ctx;
            const dataset = (this.data.datasets || [])[0] || { data: [] };
            const labels = this.data.labels || [];
            const values = dataset.data || [];
            const colors = dataset.backgroundColor || [];
            const horizontal = this.options.indexAxis === 'y';
            const maxValue = Math.max(1, ...values.map(toNumber));
            const chartArea = horizontal ? {
                left: Math.min(160, Math.max(96, width * 0.28)),
                right: 24,
                top: 24,
                bottom: 24
            } : {
                left: 48,
                right: 20,
                top: 20,
                bottom: 54
            };
            const plotWidth = width - chartArea.left - chartArea.right;
            const plotHeight = height - chartArea.top - chartArea.bottom;
            const axisColorY = (this.options?.scales?.y?.grid?.color) || 'rgba(148, 163, 184, 0.3)';
            const axisColorX = (this.options?.scales?.x?.grid?.color) || 'rgba(148, 163, 184, 0.22)';
            const textColorY = (this.options?.scales?.y?.ticks?.color) || DEFAULT_TEXT;
            const textColorX = (this.options?.scales?.x?.ticks?.color) || DEFAULT_TEXT;
            const borderRadius = Number(dataset.borderRadius || 8);

            ctx.save();
            ctx.strokeStyle = axisColorY;
            ctx.fillStyle = textColorY;
            ctx.lineWidth = 1;
            ctx.font = '500 12px Segoe UI, Arial, sans-serif';

            if (horizontal) {
                const rowHeight = labels.length ? plotHeight / labels.length : plotHeight;
                const barHeight = Math.min(28, rowHeight * 0.65);

                labels.forEach((label, index) => {
                    const yCenter = chartArea.top + (rowHeight * index) + rowHeight / 2;
                    const value = toNumber(values[index]);
                    const barWidth = (value / maxValue) * plotWidth;
                    const y = yCenter - barHeight / 2;

                    ctx.fillStyle = textColorY;
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(formatLabel(label), chartArea.left - 10, yCenter);

                    ctx.fillStyle = getColor(colors, index, '#8b5cf6');
                    roundRectPath(ctx, chartArea.left, y, Math.max(2, barWidth), barHeight, Math.min(8, barHeight / 2));
                    ctx.fill();

                    this._hitRegions.push({
                        type: 'rect',
                        index,
                        x: chartArea.left,
                        y,
                        width: Math.max(2, barWidth),
                        height: barHeight
                    });

                    ctx.fillStyle = '#e2e8f0';
                    ctx.textAlign = 'left';
                    ctx.fillText(String(value), chartArea.left + Math.max(4, barWidth + 6), yCenter);
                });

                ctx.beginPath();
                ctx.moveTo(chartArea.left, chartArea.top);
                ctx.lineTo(chartArea.left, chartArea.top + plotHeight);
                ctx.stroke();
            } else {
                const categoryWidth = labels.length ? plotWidth / labels.length : plotWidth;
                const barWidth = Math.min(Number(dataset.barThickness || 46), categoryWidth * 0.58, 72);
                const baseY = chartArea.top + plotHeight;
                const requestedStep = Number(this.options?.scales?.y?.ticks?.stepSize || 0);
                const stepSize = requestedStep > 0 ? requestedStep : Math.max(1, Math.ceil(maxValue / 4));
                const yMax = Math.max(stepSize * 2, Math.ceil(maxValue / stepSize) * stepSize);
                const steps = Math.max(2, Math.floor(yMax / stepSize));
                const datalabelConfig = this.options?.plugins?.datalabels || {};
                const datalabelColor = typeof datalabelConfig.color === 'function'
                    ? datalabelConfig.color()
                    : (datalabelConfig.color || '#f87171');

                ctx.beginPath();
                ctx.moveTo(chartArea.left, chartArea.top);
                ctx.lineTo(chartArea.left, baseY);
                ctx.lineTo(chartArea.left + plotWidth, baseY);
                ctx.stroke();

                for (let i = 0; i <= steps; i += 1) {
                    const y = baseY - ((plotHeight / yMax) * (stepSize * i));
                    ctx.strokeStyle = axisColorX;
                    ctx.beginPath();
                    ctx.moveTo(chartArea.left, y);
                    ctx.lineTo(chartArea.left + plotWidth, y);
                    ctx.stroke();

                    const tickValue = stepSize * i;
                    ctx.fillStyle = textColorY;
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(String(tickValue), chartArea.left - 8, y);
                }

                labels.forEach((label, index) => {
                    const value = toNumber(values[index]);
                    const barHeight = (value / yMax) * (plotHeight - 2);
                    const x = chartArea.left + (categoryWidth * index) + (categoryWidth - barWidth) / 2;
                    const y = baseY - barHeight;

                    ctx.fillStyle = getColor(colors, index, '#3b82f6');
                    roundRectPath(ctx, x, y, barWidth, Math.max(2, barHeight), borderRadius);
                    ctx.fill();

                    this._hitRegions.push({
                        type: 'rect',
                        index,
                        x,
                        y,
                        width: barWidth,
                        height: Math.max(2, barHeight)
                    });

                    ctx.fillStyle = textColorX;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'top';
                    ctx.fillText(formatLabel(label), x + barWidth / 2, baseY + 8);

                    ctx.fillStyle = datalabelColor;
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(String(value), x + barWidth / 2, y - 8);
                });
            }

            ctx.restore();
        }

        _drawLine(width, height) {
            const ctx = this.ctx;
            const dataset = (this.data.datasets || [])[0] || { data: [] };
            const labels = this.data.labels || [];
            const values = dataset.data || [];
            const maxValue = Math.max(1, ...values.map(toNumber));
            const padding = { left: 48, right: 24, top: 24, bottom: 44 };
            const plotWidth = width - padding.left - padding.right;
            const plotHeight = height - padding.top - padding.bottom;
            const baseY = padding.top + plotHeight;
            const textColor = (this.options?.scales?.y?.ticks?.color) || DEFAULT_TEXT;
            const gridColor = (this.options?.scales?.y?.grid?.color) || 'rgba(148, 163, 184, 0.3)';
            const strokeColor = dataset.borderColor || '#8b5cf6';
            const fillColor = dataset.backgroundColor || 'rgba(139, 92, 246, 0.12)';

            ctx.save();

            ctx.strokeStyle = gridColor;
            ctx.fillStyle = textColor;
            ctx.font = '500 12px Segoe UI, Arial, sans-serif';

            ctx.beginPath();
            ctx.moveTo(padding.left, padding.top);
            ctx.lineTo(padding.left, baseY);
            ctx.lineTo(padding.left + plotWidth, baseY);
            ctx.stroke();

            const steps = 4;
            for (let i = 0; i <= steps; i += 1) {
                const value = (maxValue / steps) * i;
                const y = baseY - ((plotHeight / steps) * i);
                ctx.strokeStyle = gridColor;
                ctx.beginPath();
                ctx.moveTo(padding.left, y);
                ctx.lineTo(padding.left + plotWidth, y);
                ctx.stroke();

                ctx.fillStyle = textColor;
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                ctx.fillText(String(Math.round(value)), padding.left - 8, y);
            }

            const points = labels.map((label, index) => {
                const x = labels.length > 1
                    ? padding.left + ((plotWidth / (labels.length - 1)) * index)
                    : padding.left + plotWidth / 2;
                const value = toNumber(values[index]);
                const y = baseY - ((value / maxValue) * plotHeight);
                return { x, y, value, label };
            });

            if (points.length) {
                ctx.beginPath();
                points.forEach((point, index) => {
                    if (index === 0) {
                        ctx.moveTo(point.x, point.y);
                    } else {
                        ctx.lineTo(point.x, point.y);
                    }
                });
                ctx.strokeStyle = strokeColor;
                ctx.lineWidth = dataset.borderWidth || 2;
                ctx.stroke();

                if (dataset.fill) {
                    ctx.lineTo(points[points.length - 1].x, baseY);
                    ctx.lineTo(points[0].x, baseY);
                    ctx.closePath();
                    ctx.fillStyle = fillColor;
                    ctx.fill();
                }

                points.forEach((point) => {
                    ctx.fillStyle = strokeColor;
                    ctx.beginPath();
                    ctx.arc(point.x, point.y, 4, 0, Math.PI * 2);
                    ctx.fill();

                    ctx.strokeStyle = '#ffffff';
                    ctx.lineWidth = 2;
                    ctx.stroke();

                    ctx.fillStyle = '#e2e8f0';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(String(point.value), point.x, point.y - 8);

                    ctx.fillStyle = textColor;
                    ctx.textBaseline = 'top';
                    ctx.fillText(formatLabel(point.label), point.x, baseY + 8);
                });
            }

            ctx.restore();
        }
    }

    global.Chart = ChartLite;
    global.ChartDataLabels = global.ChartDataLabels || { id: 'chartjs-plugin-datalabels' };
})(window);
@once
<style>
@keyframes aibm-md-clip-in {
    from {
        opacity: 0.93;
        clip-path: inset(0 100% 0 0);
    }
    to {
        opacity: 1;
        clip-path: inset(0 0 0 0);
    }
}
.aibm-clip-reveal {
    animation: aibm-md-clip-in 0.22s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    clip-path: inset(0 100% 0 0);
}

/** Dedicated Eli page: full reply fades in (no word-by-word). */
@keyframes aibm-assistant-fade-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.aibm-fade-reveal {
    animation: aibm-assistant-fade-in 0.42s ease-out forwards;
    opacity: 0;
}
.aibm-fade-reveal.aibm-fade-reveal--instant {
    animation: none;
    opacity: 1;
    transform: none;
}

.aibm-assistant-bubble .mermaid,
.aibm-float-bubble-ai .mermaid {
    margin: 10px 0;
    overflow-x: auto;
    max-width: 100%;
    white-space: normal;
}

.aibm-assistant-bubble .aibm-chart-wrap,
.aibm-float-bubble-ai .aibm-chart-wrap,
.aibm-chart-wrap {
    box-sizing: border-box;
    position: relative;
    width: 100%;
    max-width: 100%;
    margin: 12px 0 16px;
    border-radius: 16px;
    border: 1px solid rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.22);
    background: #fff;
    padding: 12px 10px 6px;
}
.aibm-grid-scroll {
    overflow-x: auto;
    margin: 12px 0 16px;
    border-radius: 14px;
    border: 1px solid rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.22);
    background: #fff;
    white-space: normal;
    word-break: normal;
}
.aibm-sheet {
    display: table;
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    line-height: 1.35;
    font-variant-numeric: tabular-nums;
    white-space: normal;
    word-break: normal;
}
.aibm-sheet th {
    background: var(--aibm-accent, #3c8dbc);
    color: #fff;
    font-weight: 600;
    text-align: right;
    padding: 9px 10px;
    white-space: nowrap;
}
.aibm-sheet th:first-child,
.aibm-sheet td:first-child {
    text-align: left;
    position: sticky;
    left: 0;
}
.aibm-sheet th:first-child {
    background: var(--aibm-accent, #3c8dbc);
}
.aibm-sheet td {
    text-align: right;
    padding: 8px 10px;
    white-space: nowrap;
    border-top: 1px solid rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.1);
    background: #fff;
}
.aibm-sheet td:first-child {
    background: #fff;
    font-weight: 600;
    color: var(--aibm-accent, #3c8dbc);
}
.aibm-sheet tbody tr:nth-child(even) td {
    background: rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.06);
}
.aibm-sheet tbody tr:nth-child(even) td:first-child {
    background: rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.06);
}
.aibm-sheet tr.aibm-grid-total td {
    background: rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.14);
    font-weight: 700;
    border-top: 1px solid rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.28);
}
.aibm-sheet tr.aibm-grid-total td:first-child {
    background: rgba(var(--aibm-accent-rgb, 60, 141, 188), 0.14);
    color: var(--aibm-accent, #3c8dbc);
}
</style>
<script type="text/javascript">
(function (w) {
    w.aibmEscapeHtml = function (s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    };

    w.aibmB64EncodeUnicode = function (str) {
        try {
            return btoa(unescape(encodeURIComponent(str)));
        } catch (e) {
            return '';
        }
    };

    w.aibmB64DecodeUnicode = function (b64) {
        try {
            return decodeURIComponent(escape(atob(b64)));
        } catch (e) {
            return '';
        }
    };

    /** Markdown-ish body without Mermaid fences (used for segments between charts). */
    w.aibmFormatAssistantTextPlain = function (text) {
        var escaped = w.aibmEscapeHtml(text || '');
        var lines = escaped.split('\n');
        var html = [];
        var inUl = false;
        var inOl = false;

        function closeLists() {
            if (inUl) {
                html.push('</ul>');
                inUl = false;
            }
            if (inOl) {
                html.push('</ol>');
                inOl = false;
            }
        }

        function inlineFormat(str) {
            return str
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code>$1</code>');
        }

        function isPipeRow(line) {
            return /^\|.*\|$/.test(line);
        }

        function isSepRow(line) {
            return /^\|[\s:\-|]+\|$/.test(line);
        }

        function splitPipes(line) {
            var cells = line.replace(/^\|/, '').replace(/\|$/, '').split('|');
            return cells.map(function (cell) {
                return cell.trim();
            });
        }

        function renderTable(header, body) {
            var out = '<div class="aibm-grid-scroll"><table class="aibm-sheet"><thead><tr>';
            header.forEach(function (cell) {
                out += '<th>' + inlineFormat(cell) + '</th>';
            });
            out += '</tr></thead><tbody>';
            body.forEach(function (row) {
                var first = (row[0] || '').replace(/\*/g, '').trim().toLowerCase();
                out += '<tr' + (first === 'total' ? ' class="aibm-grid-total"' : '') + '>';
                row.forEach(function (cell) {
                    out += '<td>' + inlineFormat(cell) + '</td>';
                });
                out += '</tr>';
            });
            out += '</tbody></table></div>';
            return out;
        }

        for (var li = 0; li < lines.length; li++) {
            var line = lines[li];
            var trimmed = line.trim();
            if (!trimmed) {
                closeLists();
                html.push('<br>');
                continue;
            }

            if (isPipeRow(trimmed) && li + 1 < lines.length && isSepRow(lines[li + 1].trim())) {
                closeLists();
                var header = splitPipes(trimmed);
                var body = [];
                li += 2;
                while (li < lines.length && isPipeRow(lines[li].trim()) && !isSepRow(lines[li].trim())) {
                    body.push(splitPipes(lines[li].trim()));
                    li++;
                }
                li--;
                html.push(renderTable(header, body));
                continue;
            }

            if (/^[-*]\s+/.test(trimmed)) {
                if (!inUl) {
                    if (inOl) {
                        html.push('</ol>');
                        inOl = false;
                    }
                    html.push('<ul style="padding-left:18px;margin:6px 0;">');
                    inUl = true;
                }
                html.push('<li>' + inlineFormat(trimmed.replace(/^[-*]\s+/, '')) + '</li>');
                continue;
            }

            if (/^\d+\.\s+/.test(trimmed)) {
                if (!inOl) {
                    if (inUl) {
                        html.push('</ul>');
                        inUl = false;
                    }
                    html.push('<ol style="padding-left:18px;margin:6px 0;">');
                    inOl = true;
                }
                html.push('<li>' + inlineFormat(trimmed.replace(/^\d+\.\s+/, '')) + '</li>');
                continue;
            }

            closeLists();

            if (/^#{1,3}\s+/.test(trimmed)) {
                var level = (trimmed.match(/^#{1,3}/) || ['#'])[0].length;
                var content = trimmed.replace(/^#{1,3}\s+/, '');
                html.push('<h' + level + ' style="margin:8px 0 6px 0;font-size:' + (18 - (level * 2)) + 'px;">' + inlineFormat(content) + '</h' + level + '>');
            } else {
                html.push('<div>' + inlineFormat(trimmed) + '</div>');
            }
        }

        closeLists();
        return html.join('');
    };

    function aibmSplitFencedBlocks(text) {
        var re = /```(mermaid|aibm-chart)\s*\r?\n([\s\S]*?)```/gi;
        var parts = [];
        var last = 0;
        var m;
        while ((m = re.exec(text)) !== null) {
            if (m.index > last) {
                parts.push({ type: 'text', content: text.slice(last, m.index) });
            }
            var fence = String(m[1] || '').toLowerCase();
            var body = (m[2] || '').replace(/\r\n/g, '\n').trim();
            parts.push({
                type: fence === 'aibm-chart' ? 'chart' : 'mermaid',
                content: body,
            });
            last = m.index + m[0].length;
        }
        if (last < text.length) {
            parts.push({ type: 'text', content: text.slice(last) });
        }
        if (parts.length === 0) {
            parts.push({ type: 'text', content: text });
        }
        return parts;
    }

    /**
     * Headings, lists, inline styles — plus ```aibm-chart``` (Chart.js, interactive) and ```mermaid``` (diagrams).
     */
    w.aibmFormatAssistantText = function (text) {
        var raw = text == null ? '' : String(text);
        var segments = aibmSplitFencedBlocks(raw);
        var out = [];
        var i;
        for (i = 0; i < segments.length; i++) {
            if (segments[i].type === 'text') {
                out.push(w.aibmFormatAssistantTextPlain(segments[i].content));
            } else {
                var enc = w.aibmB64EncodeUnicode(segments[i].content);
                if (!enc) {
                    out.push(
                        '<pre style="font-size:11px;color:#64748b;">' +
                            w.aibmEscapeHtml(segments[i].content) +
                            '</pre>'
                    );
                } else if (segments[i].type === 'chart') {
                    out.push(
                        '<div class="aibm-chart-pending" data-b64="' +
                            enc +
                            '" style="min-height:80px;margin:8px 0;background:#f8fafc;border-radius:10px;"></div>'
                    );
                } else {
                    out.push(
                        '<div class="aibm-mm-pending" data-b64="' +
                            enc +
                            '" style="min-height:24px;margin:8px 0;background:#f1f5f9;border-radius:8px;"></div>'
                    );
                }
            }
        }
        return out.join('');
    };

    w.aibmThemeAccentHex = function () {
        var el = document.querySelector('.aibm-theme-scope, .aibm-float-wrap');
        var hex = el ? (window.getComputedStyle(el).getPropertyValue('--aibm-accent') || '').trim() : '';
        return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#3c8dbc';
    };

    w.aibmChartPalette = function (accent) {
        var raw = String(accent || '').replace('#', '');
        var r = parseInt(raw.slice(0, 2), 16) / 255;
        var g = parseInt(raw.slice(2, 4), 16) / 255;
        var b = parseInt(raw.slice(4, 6), 16) / 255;
        var max = Math.max(r, g, b);
        var min = Math.min(r, g, b);
        var light = (max + min) / 2;
        var sat = 0;
        var hue = 0;
        if (max !== min) {
            var d = max - min;
            sat = light > 0.5 ? d / (2 - max - min) : d / (max + min);
            if (max === r) {
                hue = (g - b) / d + (g < b ? 6 : 0);
            } else if (max === g) {
                hue = (b - r) / d + 2;
            } else {
                hue = (r - g) / d + 4;
            }
            hue /= 6;
        }
        function hue2rgb(p, q, t) {
            if (t < 0) t += 1;
            if (t > 1) t -= 1;
            if (t < 1 / 6) return p + (q - p) * 6 * t;
            if (t < 1 / 2) return q;
            if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
            return p;
        }
        function hslHex(H, S, L) {
            var q = L < 0.5 ? L * (1 + S) : L + S - L * S;
            var p = 2 * L - q;
            var parts = [H + 1 / 3, H, H - 1 / 3].map(function (t) {
                return Math.round(hue2rgb(p, q, t) * 255).toString(16).padStart(2, '0');
            });
            return '#' + parts.join('');
        }
        var colors = [accent];
        var useSat = Math.max(0.45, Math.min(0.72, sat || 0.55));
        var useLight = Math.max(0.32, Math.min(0.48, light || 0.42));
        var step;
        for (step = 1; step < 8; step++) {
            colors.push(hslHex((hue + step * 0.13) % 1, useSat, useLight));
        }
        return colors;
    };

    w.aibmBuildChartConfigFromModel = function (raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        var allowed = {
            bar: 1,
            line: 1,
            pie: 1,
            doughnut: 1,
            radar: 1,
            polarArea: 1,
            bubble: 1,
            scatter: 1,
        };
        var t = String(raw.type || '');
        if (!allowed[t] || !raw.data || typeof raw.data !== 'object') {
            return null;
        }
        var height = 260;
        if (typeof raw.eli_height === 'number' && raw.eli_height >= 120 && raw.eli_height <= 480) {
            height = Math.floor(raw.eli_height);
        }
        var data;
        var userOpts;
        try {
            data = JSON.parse(JSON.stringify(raw.data));
        } catch (e1) {
            return null;
        }
        try {
            userOpts =
                raw.options && typeof raw.options === 'object'
                    ? JSON.parse(JSON.stringify(raw.options))
                    : {};
        } catch (e2) {
            return null;
        }
        var options = Object.assign(
            {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 6, right: 8, bottom: 0, left: 0 } },
                plugins: {},
            },
            userOpts
        );
        var currency = typeof raw.eli_currency === 'string' ? raw.eli_currency : '';
        var horizontal = options.indexAxis === 'y';
        var palette = w.aibmChartPalette(w.aibmThemeAccentHex());
        function fade(hex, alpha) {
            var h = String(hex).replace('#', '');
            if (h.length !== 6) {
                return hex;
            }
            var r = parseInt(h.slice(0, 2), 16);
            var g = parseInt(h.slice(2, 4), 16);
            var b = parseInt(h.slice(4, 6), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
        }
        function compact(v) {
            var n = Number(v);
            if (!isFinite(n)) {
                return String(v);
            }
            var abs = Math.abs(n);
            var body =
                abs >= 1000000
                    ? (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'm'
                    : abs >= 1000
                      ? (n / 1000).toFixed(abs >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k'
                      : String(Math.round(n));
            return currency ? currency + body : body;
        }
        if (data.datasets && data.datasets.length) {
            data.datasets.forEach(function (ds, i) {
                var color = palette[i % palette.length];
                if (t === 'line' || t === 'bar') {
                    ds.borderColor = color;
                    ds.backgroundColor = t === 'line' ? fade(color, 0.16) : fade(color, 0.9);
                    ds.borderWidth = t === 'line' ? 2.5 : 0;
                    if (t === 'bar') {
                        ds.borderRadius = 6;
                        ds.borderSkipped = false;
                        ds.maxBarThickness = 28;
                    }
                    if (t === 'line') {
                        ds.tension = 0.35;
                        ds.fill = true;
                        ds.pointRadius = 3;
                        ds.pointHoverRadius = 6;
                        ds.pointBackgroundColor = '#fff';
                        ds.pointBorderColor = color;
                        ds.pointBorderWidth = 2;
                    }
                } else if (!ds.backgroundColor) {
                    ds.backgroundColor = palette.slice(0, (ds.data || []).length || palette.length).map(function (c) {
                        return fade(c, 0.9);
                    });
                    ds.borderWidth = 0;
                }
            });
        }
        function axisStyle(axis, stacked) {
            var showGrid = horizontal ? axis === 'x' : axis === 'y';
            return {
                stacked: !!stacked,
                beginAtZero: true,
                grid: { display: showGrid, color: 'rgba(15, 23, 42, 0.08)', drawTicks: false },
                border: { display: false },
                ticks: {
                    color: '#64748b',
                    padding: 6,
                    font: { size: 11, family: 'ui-sans-serif, system-ui, sans-serif' },
                    callback: function (value) {
                        if (horizontal ? axis === 'y' : axis === 'x') {
                            return this.getLabelForValue ? this.getLabelForValue(value) : value;
                        }
                        return compact(value);
                    },
                },
            };
        }
        var modelScales = options.scales && typeof options.scales === 'object' ? options.scales : {};
        if (t === 'bar' || t === 'line') {
            options.scales = {
                x: Object.assign(
                    axisStyle('x', modelScales.x && modelScales.x.stacked),
                    { ticks: Object.assign(axisStyle('x', false).ticks, { maxRotation: horizontal ? 0 : 0, autoSkip: true }) }
                ),
                y: axisStyle('y', modelScales.y && modelScales.y.stacked),
            };
            if (modelScales.x && modelScales.x.stacked) {
                options.scales.x.stacked = true;
            }
            if (modelScales.y && modelScales.y.stacked) {
                options.scales.y.stacked = true;
            }
        }
        options.plugins = Object.assign(
            {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 14,
                        color: '#1e293b',
                        boxWidth: 8,
                        font: { size: 12, family: 'ui-sans-serif, system-ui, sans-serif' },
                    },
                },
                tooltip: {
                    enabled: true,
                    intersect: false,
                    backgroundColor: w.aibmThemeAccentHex(),
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 10,
                    displayColors: true,
                    callbacks: {
                        label: function (ctx) {
                            var rawV = horizontal ? ctx.parsed.x : ctx.parsed.y;
                            var shown = currency
                                ? currency + Number(rawV || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })
                                : compact(rawV);
                            return (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + shown;
                        },
                    },
                },
            },
            options.plugins || {}
        );
        return { cfg: { type: t, data: data, options: options }, height: height };
    };

    w.aibmEnsureMermaidLoaded = function (cb) {
        if (w.mermaid) {
            cb();
            return;
        }
        if (w._aibmMermaidLoadQueue) {
            w._aibmMermaidLoadQueue.push(cb);
            return;
        }
        w._aibmMermaidLoadQueue = [cb];
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js';
        s.onload = function () {
            try {
                w.mermaid.initialize({
                    startOnLoad: false,
                    securityLevel: 'strict',
                    theme: 'neutral',
                });
            } catch (eInit) {}
            var q = w._aibmMermaidLoadQueue || [];
            w._aibmMermaidLoadQueue = null;
            q.forEach(function (fn) {
                try {
                    fn();
                } catch (e2) {}
            });
        };
        s.onerror = function () {
            var q = w._aibmMermaidLoadQueue || [];
            w._aibmMermaidLoadQueue = null;
            q.forEach(function (fn) {
                try {
                    fn();
                } catch (e2) {}
            });
        };
        document.head.appendChild(s);
    };

    /**
     * Turn pending chart placeholders under `root` into SVG (async). Safe to call when no charts.
     */
    w.aibmPrepareAndRunMermaid = function (root, done) {
        done = typeof done === 'function' ? done : function () {};
        if (!root || !root.querySelectorAll) {
            done();
            return;
        }
        var slots = root.querySelectorAll('.aibm-mm-pending');
        if (!slots.length) {
            done();
            return;
        }
        slots.forEach(function (slot) {
            var b64 = slot.getAttribute('data-b64') || '';
            var diagram = w.aibmB64DecodeUnicode(b64);
            var div = document.createElement('div');
            div.className = 'mermaid aibm-mm-fresh';
            div.textContent = diagram;
            if (slot.parentNode) {
                slot.parentNode.replaceChild(div, slot);
            }
        });
        w.aibmEnsureMermaidLoaded(function () {
            if (!w.mermaid || typeof w.mermaid.run !== 'function') {
                done();
                return;
            }
            var nodes = Array.from(root.querySelectorAll('div.mermaid.aibm-mm-fresh'));
            if (!nodes.length) {
                done();
                return;
            }
            try {
                var runResult = w.mermaid.run({ nodes: nodes });
                if (runResult && typeof runResult.then === 'function') {
                    runResult
                        .then(function () {
                            nodes.forEach(function (n) {
                                n.classList.remove('aibm-mm-fresh');
                            });
                            done();
                        })
                        .catch(function () {
                            done();
                        });
                } else {
                    nodes.forEach(function (n) {
                        n.classList.remove('aibm-mm-fresh');
                    });
                    done();
                }
            } catch (eRun) {
                done();
            }
        });
    };

    w.aibmEnsureChartJsLoaded = function (cb) {
        if (w.Chart) {
            cb();
            return;
        }
        if (w._aibmChartLoadQueue) {
            w._aibmChartLoadQueue.push(cb);
            return;
        }
        w._aibmChartLoadQueue = [cb];
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
        s.onload = function () {
            var q = w._aibmChartLoadQueue || [];
            w._aibmChartLoadQueue = null;
            q.forEach(function (fn) {
                try {
                    fn();
                } catch (e2) {}
            });
        };
        s.onerror = function () {
            var q = w._aibmChartLoadQueue || [];
            w._aibmChartLoadQueue = null;
            q.forEach(function (fn) {
                try {
                    fn();
                } catch (e2) {}
            });
        };
        document.head.appendChild(s);
    };

    /**
     * Chart.js (tooltips, legend dataset toggle, hover) from ```aibm-chart … ``` JSON.
     */
    w.aibmPrepareAndRunCharts = function (root, done) {
        done = typeof done === 'function' ? done : function () {};
        if (!root || !root.querySelectorAll) {
            done();
            return;
        }
        var slots = root.querySelectorAll('.aibm-chart-pending');
        if (!slots.length) {
            done();
            return;
        }
        var pendingCharts = [];
        slots.forEach(function (slot) {
            var b64 = slot.getAttribute('data-b64') || '';
            var jsonStr = w.aibmB64DecodeUnicode(b64);
            var wrap = document.createElement('div');
            wrap.className = 'aibm-chart-wrap';
            var err =
                '<div style="padding:12px;font-size:12px;color:#b91c1c;">Invalid or unparsable chart.</div>';
            try {
                var parsed = JSON.parse(jsonStr);
                var built = w.aibmBuildChartConfigFromModel(parsed);
                if (!built) {
                    wrap.innerHTML = err;
                } else {
                    wrap.style.height = built.height + 'px';
                    var canvas = document.createElement('canvas');
                    canvas.setAttribute('role', 'img');
                    wrap.appendChild(canvas);
                    pendingCharts.push({ canvas: canvas, cfg: built.cfg });
                }
            } catch (eParse) {
                wrap.innerHTML = err;
            }
            if (slot.parentNode) {
                slot.parentNode.replaceChild(wrap, slot);
            }
        });
        if (!pendingCharts.length) {
            done();
            return;
        }
        w.aibmEnsureChartJsLoaded(function () {
            if (!w.Chart) {
                done();
                return;
            }
            pendingCharts.forEach(function (p) {
                try {
                    if (p.canvas.aibmChart && typeof p.canvas.aibmChart.destroy === 'function') {
                        p.canvas.aibmChart.destroy();
                    }
                    p.canvas.aibmChart = new w.Chart(p.canvas, p.cfg);
                } catch (eChart) {}
            });
            done();
        });
    };

    /** Run interactive Chart.js blocks and Mermaid diagrams under `root` (parallel load). */
    w.aibmFinalizeRichContent = function (root, done) {
        done = typeof done === 'function' ? done : function () {};
        var n = 2;
        function fin() {
            n -= 1;
            if (n <= 0) {
                done();
            }
        }
        w.aibmPrepareAndRunCharts(root, fin);
        w.aibmPrepareAndRunMermaid(root, fin);
    };

    /**
     * Full formatted reply fades in (used on the dedicated Eli chat page).
     */
    w.aibmRevealAssistantFadeIn = function (contentEl, plainText, scrollEl, opts) {
        opts = opts || {};
        var reduceMotion =
            w.matchMedia &&
            w.matchMedia('(prefers-reduced-motion: reduce)').matches;
        plainText = plainText == null ? '' : String(plainText);

        function scrollNow() {
            if (scrollEl) scrollEl.scrollTop = scrollEl.scrollHeight;
        }

        var html = w.aibmFormatAssistantText(plainText);
        var innerClass = reduceMotion
            ? 'aibm-fade-reveal aibm-fade-reveal--instant'
            : 'aibm-fade-reveal';
        contentEl.innerHTML = '<div class="' + innerClass + '">' + html + '</div>';
        scrollNow();
        function runCharts() {
            w.aibmFinalizeRichContent(contentEl, function () {
                scrollNow();
            });
        }
        if (w.requestAnimationFrame) {
            w.requestAnimationFrame(function () {
                w.requestAnimationFrame(runCharts);
            });
        } else {
            w.setTimeout(runCharts, 0);
        }
    };

    /**
     * Word-by-word type reveal (floating Eli widget): plain text during playback, then formatted Markdown HTML + short clip-path finish.
     * Defaults tuned for readable pacing (override via opts.wordsPerTick / opts.intervalMs).
     */
    w.aibmRevealAssistantReply = function (contentEl, plainText, scrollEl, opts) {
        opts = opts || {};
        var reduceMotion =
            w.matchMedia &&
            w.matchMedia('(prefers-reduced-motion: reduce)').matches;
        plainText = plainText == null ? '' : String(plainText);

        function scrollNow() {
            if (scrollEl) scrollEl.scrollTop = scrollEl.scrollHeight;
        }

        function revealHtmlAndCharts() {
            contentEl.innerHTML =
                '<div class="aibm-clip-reveal">' + w.aibmFormatAssistantText(plainText) + '</div>';
            scrollNow();
            w.aibmFinalizeRichContent(contentEl, function () {
                scrollNow();
            });
        }

        if (/```(mermaid|aibm-chart)/i.test(plainText) || /(^|\n)\|[^\n]+\|\s*\n\|[\s:|-]+\|/m.test(plainText)) {
            revealHtmlAndCharts();
            return;
        }

        if (reduceMotion) {
            revealHtmlAndCharts();
            return;
        }

        var wordsPerTick =
            typeof opts.wordsPerTick === 'number' ? opts.wordsPerTick : 1;
        var intervalMs =
            typeof opts.intervalMs === 'number' ? opts.intervalMs : 68;

        var parts = plainText.match(/\S+\s*/g);
        if (!parts || parts.length === 0) {
            revealHtmlAndCharts();
            return;
        }
        var i = 0;
        function finish() {
            revealHtmlAndCharts();
        }
        function tick() {
            i = Math.min(i + wordsPerTick, parts.length);
            contentEl.textContent = parts.slice(0, i).join('');
            scrollNow();
            if (i < parts.length) {
                window.setTimeout(tick, intervalMs);
            } else {
                finish();
            }
        }
        tick();
    };
})(window);
</script>
@endonce

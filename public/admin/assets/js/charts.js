(() => {
    'use strict';

    const RIGHT_OFFSET_BARS = 300;
    const VIEW_STORAGE_KEY = 'tradesignals.chartView.v2';
    const MA_STORAGE_KEY = 'tradesignals.chartMa.v1';
    const PC_STORAGE_KEY = 'tradesignals.chartPc.v1';
    const TF_STORAGE_KEY = 'tradesignals.chartTf.v1';
    const TF_COOKIE = 'tradesignals_chart_tf';
    const MA_PERIODS = [
        { period: 28, color: '#3b82f6', key: 'ma28' },
    ];
    /** Параметры как в Pine «Price Channel + Trend Flip». */
    const PC_LENGTH = 20;
    const TREND_FLIP_LINES = 5;

    function persistChartTimeframe(tf) {
        if (!tf || typeof tf !== 'string') {
            return;
        }
        try {
            localStorage.setItem(TF_STORAGE_KEY, tf);
        } catch (_error) {
            // ignore
        }
        try {
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = `${TF_COOKIE}=${encodeURIComponent(tf)}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
        } catch (_error) {
            // ignore
        }
    }

    function readSavedChartTimeframe() {
        try {
            const fromLs = localStorage.getItem(TF_STORAGE_KEY);
            if (fromLs) {
                return fromLs;
            }
        } catch (_error) {
            // ignore
        }
        try {
            const match = document.cookie.match(new RegExp(`(?:^|; )${TF_COOKIE}=([^;]*)`));
            if (match) {
                return decodeURIComponent(match[1]);
            }
        } catch (_error) {
            // ignore
        }
        return null;
    }

    function readMaEnabled() {
        try {
            return localStorage.getItem(MA_STORAGE_KEY) === '1';
        } catch (_error) {
            return false;
        }
    }

    function writeMaEnabled(enabled) {
        try {
            localStorage.setItem(MA_STORAGE_KEY, enabled ? '1' : '0');
        } catch (_error) {
            // ignore
        }
    }

    function readPcEnabled() {
        try {
            return localStorage.getItem(PC_STORAGE_KEY) === '1';
        } catch (_error) {
            return false;
        }
    }

    function writePcEnabled(enabled) {
        try {
            localStorage.setItem(PC_STORAGE_KEY, enabled ? '1' : '0');
        } catch (_error) {
            // ignore
        }
    }

    /** Простая скользящая средняя по close. */
    function computeSma(candles, period) {
        const out = [];
        if (!Array.isArray(candles) || period < 1 || candles.length < period) {
            return out;
        }
        let sum = 0;
        for (let i = 0; i < candles.length; i += 1) {
            sum += Number(candles[i].close);
            if (i >= period) {
                sum -= Number(candles[i - period].close);
            }
            if (i >= period - 1) {
                out.push({
                    time: candles[i].time,
                    value: sum / period,
                });
            }
        }
        return out;
    }

    function nearlyEqual(a, b) {
        return Math.abs(Number(a) - Number(b)) < 1e-8;
    }

    function highestInRange(candles, from, to) {
        let m = -Infinity;
        for (let i = from; i <= to; i += 1) {
            const h = Number(candles[i].high);
            if (h > m) {
                m = h;
            }
        }
        return m;
    }

    function lowestInRange(candles, from, to) {
        let m = Infinity;
        for (let i = from; i <= to; i += 1) {
            const l = Number(candles[i].low);
            if (l < m) {
                m = l;
            }
        }
        return m;
    }

    /**
     * Price Channel signals (Pine):
     * upper/lower = highest/lowest за length; long = high == upper[1], short = low == lower[1].
     */
    function computePriceChannelMarkers(candles, length = PC_LENGTH) {
        const markers = [];
        if (!Array.isArray(candles) || candles.length < length + 1) {
            return markers;
        }
        for (let i = length; i < candles.length; i += 1) {
            const upperPrev = highestInRange(candles, i - length, i - 1);
            const lowerPrev = lowestInRange(candles, i - length, i - 1);
            const high = Number(candles[i].high);
            const low = Number(candles[i].low);
            if (nearlyEqual(high, upperPrev)) {
                markers.push({
                    time: candles[i].time,
                    position: 'belowBar',
                    color: '#22c55e',
                    shape: 'arrowUp',
                    text: 'BUY',
                });
            }
            if (nearlyEqual(low, lowerPrev)) {
                markers.push({
                    time: candles[i].time,
                    position: 'aboveBar',
                    color: '#ef4444',
                    shape: 'arrowDown',
                    text: 'SELL',
                });
            }
        }
        return markers;
    }

    /**
     * Trend Flip (Pine): стрелки разворота + последние уровни flipLevel.
     * @returns {{markers: array, upLevels: number[], downLevels: number[]}}
     */
    function computeTrendFlip(candles, linesCount = TREND_FLIP_LINES) {
        const markers = [];
        const upLevels = [];
        const downLevels = [];
        if (!Array.isArray(candles) || candles.length === 0) {
            return { markers, upLevels, downLevels };
        }

        let trend = null;
        let lastFlipClose = null;
        let extremeOpen = null;
        let extremeClose = null;

        for (let i = 0; i < candles.length; i += 1) {
            const open = Number(candles[i].open);
            const close = Number(candles[i].close);
            let flip = false;
            let flipLevel = null;

            if (lastFlipClose === null) {
                trend = close > open ? 'up' : 'down';
                lastFlipClose = close;
                extremeOpen = open;
                extremeClose = close;
            }

            if (trend === 'up') {
                if (close > open) {
                    if (close > extremeClose) {
                        extremeClose = close;
                        extremeOpen = open;
                    }
                } else if (close < extremeOpen) {
                    flipLevel = extremeOpen;
                    flip = true;
                    trend = 'down';
                    extremeClose = close;
                    extremeOpen = open;
                }
            } else if (trend === 'down') {
                if (close < open) {
                    if (close < extremeClose) {
                        extremeClose = close;
                        extremeOpen = open;
                    }
                } else if (close > extremeOpen) {
                    flipLevel = extremeOpen;
                    flip = true;
                    trend = 'up';
                    extremeClose = close;
                    extremeOpen = open;
                }
            }

            if (flip) {
                const delta = trend === 'up'
                    ? lastFlipClose - close
                    : -(lastFlipClose - close);
                // Как в Pine: ↑ красная снизу, ↓ зелёная сверху.
                if (trend === 'up') {
                    markers.push({
                        time: candles[i].time,
                        position: 'belowBar',
                        color: '#ef4444',
                        shape: 'arrowUp',
                        text: delta > 0 ? `↑ ${Math.round(delta)}` : '↑',
                    });
                    if (flipLevel != null && Number.isFinite(flipLevel)) {
                        upLevels.unshift(flipLevel);
                        if (upLevels.length > linesCount) {
                            upLevels.length = linesCount;
                        }
                    }
                } else {
                    markers.push({
                        time: candles[i].time,
                        position: 'aboveBar',
                        color: '#22c55e',
                        shape: 'arrowDown',
                        text: delta > 0 ? `↓ ${Math.round(delta)}` : '↓',
                    });
                    if (flipLevel != null && Number.isFinite(flipLevel)) {
                        downLevels.unshift(flipLevel);
                        if (downLevels.length > linesCount) {
                            downLevels.length = linesCount;
                        }
                    }
                }
                lastFlipClose = close;
            }
        }

        return { markers, upLevels, downLevels };
    }

    function mergeMarkers(...groups) {
        const all = [];
        groups.forEach((group) => {
            if (Array.isArray(group)) {
                all.push(...group);
            }
        });
        all.sort((a, b) => {
            if (a.time === b.time) {
                return 0;
            }
            if (typeof a.time === 'string' || typeof b.time === 'string') {
                return String(a.time).localeCompare(String(b.time));
            }
            return a.time - b.time;
        });
        // Как max_labels_count=100 в Pine — не перегружаем график.
        if (all.length > 100) {
            return all.slice(all.length - 100);
        }
        return all;
    }

    const PC_LINE_GREENS = ['#16a34a', '#22c55e', '#4ade80', '#86efac', '#bbf7d0'];
    const PC_LINE_REDS = ['#dc2626', '#ef4444', '#f87171', '#fca5a5', '#fecaca'];

    function createChart(container) {
        const chart = LightweightCharts.createChart(container, {
            layout: {
                background: { color: '#0d1117' },
                textColor: '#9aa4b2',
            },
            grid: {
                vertLines: { color: '#1f2937' },
                horzLines: { color: '#1f2937' },
            },
            rightPriceScale: { borderColor: '#30363d' },
            timeScale: {
                borderColor: '#30363d',
                timeVisible: true,
                secondsVisible: false,
                rightOffset: RIGHT_OFFSET_BARS,
                shiftVisibleRangeOnNewBar: false,
            },
            crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
        });

        const series = chart.addCandlestickSeries({
            upColor: '#22c55e',
            downColor: '#ef4444',
            borderVisible: false,
            wickUpColor: '#22c55e',
            wickDownColor: '#ef4444',
        });

        const maSeries = {};
        MA_PERIODS.forEach((item) => {
            maSeries[item.key] = chart.addLineSeries({
                color: item.color,
                lineWidth: 1,
                priceLineVisible: false,
                lastValueVisible: false,
                crosshairMarkerVisible: false,
                visible: false,
                title: `MA${item.period}`,
            });
        });

        let lastCandles = [];
        let maEnabled = false;
        let pcEnabled = false;
        /** @type {Array<{remove: Function}>} */
        let pcPriceLines = [];

        const clearPcPriceLines = () => {
            pcPriceLines.forEach((line) => {
                try {
                    series.removePriceLine(line);
                } catch (_error) {
                    // ignore
                }
            });
            pcPriceLines = [];
        };

        const applyMaData = () => {
            MA_PERIODS.forEach((item) => {
                const line = maSeries[item.key];
                if (!line) {
                    return;
                }
                if (!maEnabled || lastCandles.length < item.period) {
                    line.setData([]);
                    line.applyOptions({ visible: false });
                    return;
                }
                line.setData(computeSma(lastCandles, item.period));
                line.applyOptions({ visible: true });
            });
        };

        const applyPcData = () => {
            clearPcPriceLines();
            if (!pcEnabled || lastCandles.length === 0) {
                try {
                    series.setMarkers([]);
                } catch (_error) {
                    // ignore
                }
                return;
            }

            const pcMarkers = computePriceChannelMarkers(lastCandles, PC_LENGTH);
            const flip = computeTrendFlip(lastCandles, TREND_FLIP_LINES);
            series.setMarkers(mergeMarkers(pcMarkers, flip.markers));

            flip.upLevels.forEach((price, idx) => {
                const line = series.createPriceLine({
                    price,
                    color: PC_LINE_GREENS[idx] || '#22c55e',
                    lineWidth: 2,
                    lineStyle: LightweightCharts.LineStyle.Solid,
                    axisLabelVisible: true,
                    title: `↑${idx + 1}`,
                });
                pcPriceLines.push(line);
            });
            flip.downLevels.forEach((price, idx) => {
                const line = series.createPriceLine({
                    price,
                    color: PC_LINE_REDS[idx] || '#ef4444',
                    lineWidth: 2,
                    lineStyle: LightweightCharts.LineStyle.Solid,
                    axisLabelVisible: true,
                    title: `↓${idx + 1}`,
                });
                pcPriceLines.push(line);
            });
        };

        const setMaEnabled = (enabled) => {
            maEnabled = !!enabled;
            applyMaData();
        };

        const setPcEnabled = (enabled) => {
            pcEnabled = !!enabled;
            applyPcData();
        };

        const resize = () => {
            chart.applyOptions({
                width: container.clientWidth,
                height: container.clientHeight,
            });
        };
        resize();
        window.addEventListener('resize', resize);

        attachChartNav(container, chart);
        attachMaLegend(container);
        attachPcLegend(container);

        return {
            chart,
            series,
            maSeries,
            resize,
            container,
            setMaEnabled,
            setPcEnabled,
            setLastCandles(candles) {
                lastCandles = Array.isArray(candles) ? candles : [];
                applyMaData();
                applyPcData();
            },
        };
    }

    function attachMaLegend(container) {
        if (container.querySelector('.chart-ma-legend')) {
            return;
        }
        const legend = document.createElement('div');
        legend.className = 'chart-ma-legend';
        legend.hidden = true;
        legend.innerHTML = MA_PERIODS.map(
            (item) => `<span style="color:${item.color}">MA${item.period}</span>`
        ).join('');
        container.appendChild(legend);
    }

    function attachPcLegend(container) {
        if (container.querySelector('.chart-pc-legend')) {
            return;
        }
        const legend = document.createElement('div');
        legend.className = 'chart-pc-legend';
        legend.hidden = true;
        legend.innerHTML = [
            '<span style="color:#22c55e">PC BUY</span>',
            '<span style="color:#ef4444">PC SELL</span>',
            '<span style="color:#ef4444">Flip ↑</span>',
            '<span style="color:#22c55e">Flip ↓</span>',
        ].join('');
        container.appendChild(legend);
    }

    function setMaLegendVisible(container, visible) {
        const legend = container.querySelector('.chart-ma-legend');
        if (legend) {
            legend.hidden = !visible;
        }
    }

    function setPcLegendVisible(container, visible) {
        const legend = container.querySelector('.chart-pc-legend');
        if (legend) {
            legend.hidden = !visible;
        }
    }

    function scrollChartToEnd(chart) {
        const ts = chart.timeScale();
        ts.applyOptions({ rightOffset: RIGHT_OFFSET_BARS });
        ts.scrollToRealTime();
    }

    function scrollChartToStart(chart) {
        const ts = chart.timeScale();
        const range = ts.getVisibleLogicalRange();
        const span = range ? Math.max(range.to - range.from, 20) : 80;
        ts.setVisibleLogicalRange({
            from: 0,
            to: span,
        });
    }

    function attachChartNav(container, chart) {
        if (container.querySelector('.chart-nav')) {
            return;
        }
        const nav = document.createElement('div');
        nav.className = 'chart-nav';
        nav.innerHTML = `
            <button type="button" class="chart-nav-btn" data-nav="start" title="К началу графика">« Начало</button>
            <button type="button" class="chart-nav-btn" data-nav="end" title="К концу графика">Конец »</button>
        `;
        nav.addEventListener('mousedown', (event) => event.stopPropagation());
        nav.addEventListener('dblclick', (event) => event.stopPropagation());
        nav.querySelector('[data-nav="start"]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            scrollChartToStart(chart);
        });
        nav.querySelector('[data-nav="end"]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            scrollChartToEnd(chart);
        });
        container.appendChild(nav);
    }

    function applyDefaultView(chart) {
        const ts = chart.timeScale();
        ts.applyOptions({ rightOffset: RIGHT_OFFSET_BARS });
        ts.scrollToRealTime();
    }

    function readViewStore() {
        try {
            return JSON.parse(localStorage.getItem(VIEW_STORAGE_KEY) || '{}') || {};
        } catch (_error) {
            return {};
        }
    }

    function writeViewStore(store) {
        try {
            localStorage.setItem(VIEW_STORAGE_KEY, JSON.stringify(store));
        } catch (_error) {
            // ignore
        }
    }

    function loadPersistedView(viewKey) {
        const state = readViewStore()[viewKey];
        if (!state?.logical || state.logical.from == null || state.logical.to == null) {
            return null;
        }
        return state;
    }

    function persistView(viewKey, state) {
        if (!viewKey || !state?.logical) {
            return;
        }
        const store = readViewStore();
        store[viewKey] = state;
        writeViewStore(store);
    }

    function captureView(chart, series, barCount) {
        const logical = chart.timeScale().getVisibleLogicalRange();
        if (!logical || logical.from == null || logical.to == null) {
            return null;
        }

        let price = null;
        let autoScale = true;
        try {
            const priceScale = series.priceScale();
            const opts = typeof priceScale.options === 'function' ? priceScale.options() : null;
            autoScale = !opts || opts.autoScale !== false;
            const visiblePrice = priceScale.getVisibleRange();
            if (!autoScale && visiblePrice && visiblePrice.from != null && visiblePrice.to != null) {
                price = { from: visiblePrice.from, to: visiblePrice.to };
            }
        } catch (_error) {
            price = null;
        }

        let rightOffset = RIGHT_OFFSET_BARS;
        try {
            const tsOpts = chart.timeScale().options();
            if (tsOpts && tsOpts.rightOffset != null) {
                rightOffset = tsOpts.rightOffset;
            }
        } catch (_error) {
            rightOffset = RIGHT_OFFSET_BARS;
        }

        return {
            logical: { from: logical.from, to: logical.to },
            barCount: barCount ?? null,
            rightOffset,
            price,
            autoScale,
        };
    }

    function resolveLogicalRange(state, barCount) {
        let from = state.logical.from;
        let to = state.logical.to;

        if (
            state.barCount != null &&
            barCount != null &&
            barCount !== state.barCount
        ) {
            const delta = barCount - state.barCount;
            const wasFollowingRightEdge = state.logical.to > state.barCount - 1;
            if (wasFollowingRightEdge) {
                from += delta;
                to += delta;
            }
        }

        return { from, to };
    }

    function restoreView(chart, series, state, barCount) {
        if (!state?.logical) {
            return false;
        }

        try {
            const ts = chart.timeScale();
            const rightOffset = state.rightOffset != null ? state.rightOffset : RIGHT_OFFSET_BARS;
            ts.applyOptions({ rightOffset });
            ts.setVisibleLogicalRange(resolveLogicalRange(state, barCount));

            const priceScale = series.priceScale();
            if (state.price && state.price.from != null && state.price.to != null && state.autoScale === false) {
                priceScale.applyOptions({ autoScale: false });
                priceScale.setVisibleRange({
                    from: state.price.from,
                    to: state.price.to,
                });
            } else {
                priceScale.applyOptions({ autoScale: true });
            }

            return true;
        } catch (_error) {
            return false;
        }
    }

    /**
     * Обновляет только хвост через update() — viewport не сбрасывается.
     * LW Charts разрешает update лишь для последнего бара или бара новее него.
     * setData() — при первой загрузке / сильном расхождении / ошибке update.
     */
    function pushCandlesIncremental(series, prevCount, candles) {
        const nextCount = candles.length;
        if (prevCount <= 0 || nextCount <= 0) {
            return false;
        }
        if (nextCount < prevCount || nextCount > prevCount + 5) {
            return false;
        }

        // Только последний существующий бар (заменить) и новые после него (добавить).
        const start = Math.max(0, prevCount - 1);
        try {
            for (let i = start; i < nextCount; i += 1) {
                series.update(candles[i]);
            }
            return true;
        } catch (_error) {
            return false;
        }
    }

    function bindViewPersistence(chart, series, container, viewKey) {
        let saveTimer = null;
        let unlockTimer = null;
        let applying = false;
        let barCount = 0;
        let state = loadPersistedView(viewKey);

        const lock = () => {
            applying = true;
            window.clearTimeout(saveTimer);
            window.clearTimeout(unlockTimer);
        };

        const unlock = (delayMs = 400) => {
            window.clearTimeout(unlockTimer);
            unlockTimer = window.setTimeout(() => {
                applying = false;
            }, delayMs);
        };

        const scheduleSave = () => {
            if (applying || !viewKey || barCount <= 0) {
                return;
            }
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(() => {
                if (applying) {
                    return;
                }
                const next = captureView(chart, series, barCount);
                if (!next) {
                    return;
                }
                state = next;
                persistView(viewKey, next);
            }, 200);
        };

        const flushSave = () => {
            if (applying || !viewKey || barCount <= 0) {
                return;
            }
            window.clearTimeout(saveTimer);
            const next = captureView(chart, series, barCount);
            if (!next) {
                return;
            }
            state = next;
            persistView(viewKey, next);
        };

        chart.timeScale().subscribeVisibleLogicalRangeChange(scheduleSave);
        container.addEventListener('mouseup', scheduleSave);
        container.addEventListener('touchend', scheduleSave, { passive: true });
        container.addEventListener('wheel', scheduleSave, { passive: true });
        window.addEventListener('pagehide', flushSave);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                flushSave();
            }
        });

        /**
         * Единая точка обновления данных: лочим сохранение ДО setData/update,
         * восстанавливаем вид синхронно и ещё раз в rAF (LW иногда сбрасывает кадр позже).
         */
        function setCandles(candles) {
            lock();

            const preferred = state || loadPersistedView(viewKey);
            const prevCount = barCount;
            const nextCount = candles.length;
            const chartCandles = candles.map((candle) => ({
                time: candle.time,
                open: candle.open,
                high: candle.high,
                low: candle.low,
                close: candle.close,
            }));
            const usedIncremental = pushCandlesIncremental(series, prevCount, chartCandles);

            if (!usedIncremental) {
                series.setData(chartCandles);
            }

            barCount = nextCount;

            const applyPreferred = () => {
                if (nextCount <= 0) {
                    return false;
                }
                if (preferred) {
                    return restoreView(chart, series, preferred, nextCount);
                }
                applyDefaultView(chart);
                return false;
            };

            if (usedIncremental) {
                // Viewport сам сохранился; только обновим barCount в state.
                if (preferred) {
                    const logical = resolveLogicalRange(preferred, nextCount);
                    state = {
                        ...preferred,
                        logical,
                        barCount: nextCount,
                    };
                    persistView(viewKey, state);
                }
                unlock(150);
                return;
            }

            // setData сбрасывает масштаб — сразу возвращаем сохранённый вид.
            const restored = applyPreferred();
            if (preferred && restored) {
                const logical = resolveLogicalRange(preferred, nextCount);
                state = {
                    ...preferred,
                    logical,
                    barCount: nextCount,
                };
                persistView(viewKey, state);
            }

            window.requestAnimationFrame(() => {
                applyPreferred();
                window.requestAnimationFrame(() => {
                    applyPreferred();

                    // Первый заход без сохранённого вида — запоминаем дефолт с rightOffset.
                    if (!preferred && nextCount > 0) {
                        const current = captureView(chart, series, nextCount);
                        if (current) {
                            state = current;
                            persistView(viewKey, current);
                        }
                    }

                    unlock(400);
                });
            });
        }

        return { setCandles };
    }

    function createDashboard({ endpoint, containerSelector, priceSelector }) {
        const hosts = Array.from(document.querySelectorAll(containerSelector));
        const charts = new Map();
        let maEnabled = readMaEnabled();
        let pcEnabled = readPcEnabled();

        hosts.forEach((host) => {
            const entry = createChart(host);
            const label = host.dataset.interval;
            entry.view = bindViewPersistence(
                entry.chart,
                entry.series,
                entry.container,
                `dashboard:${label}`
            );
            entry.setMaEnabled(maEnabled);
            setMaLegendVisible(entry.container, maEnabled);
            entry.setPcEnabled(pcEnabled);
            setPcLegendVisible(entry.container, pcEnabled);
            charts.set(label, entry);
        });

        function setMaEnabled(enabled) {
            maEnabled = !!enabled;
            writeMaEnabled(maEnabled);
            charts.forEach((entry) => {
                entry.setMaEnabled(maEnabled);
                setMaLegendVisible(entry.container, maEnabled);
            });
            return maEnabled;
        }

        function isMaEnabled() {
            return maEnabled;
        }

        function setPcEnabled(enabled) {
            pcEnabled = !!enabled;
            writePcEnabled(pcEnabled);
            charts.forEach((entry) => {
                entry.setPcEnabled(pcEnabled);
                setPcLegendVisible(entry.container, pcEnabled);
            });
            return pcEnabled;
        }

        function isPcEnabled() {
            return pcEnabled;
        }

        async function load() {
            const response = await fetch(endpoint, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Не удалось загрузить свечи.');
            }
            const payload = await response.json();
            let lastClose = null;

            Object.entries(payload.intervals || {}).forEach(([label, item]) => {
                const entry = charts.get(label);
                const meta = document.querySelector(`.chart-meta[data-label="${label}"]`);
                const seqEl = document.querySelector(`.chart-seq[data-label="${label}"]`);
                const lastSignalEl = document.querySelector(`.chart-last-signal[data-label="${label}"]`);
                if (!entry) {
                    return;
                }
                const candles = item.candles || [];
                entry.view.setCandles(candles);
                entry.setLastCandles(candles);
                if (seqEl) {
                    const seq = item.sequence || {};
                    const seqLabel = seq.label || '—';
                    seqEl.textContent = `(${seqLabel})`;
                    seqEl.title = seq.reason
                        ? `Причина: ${seq.reason}`
                        : (seq.direction
                            ? `Последовательность по закрытым барам, мин. тело ${seq.min_body ?? '—'}`
                            : '');
                    seqEl.classList.remove('text-success', 'text-danger', 'text-secondary', 'text-warning');
                    if (seq.direction === 'up') {
                        seqEl.classList.add('text-success');
                    } else if (seq.direction === 'down') {
                        seqEl.classList.add('text-danger');
                    } else if (seq.reason) {
                        seqEl.classList.add('text-warning');
                    } else {
                        seqEl.classList.add('text-secondary');
                    }
                }
                if (lastSignalEl) {
                    const last = item.last_signal;
                    if (last && last.label) {
                        lastSignalEl.textContent = `· отпр. ${last.label} @ ${last.sent_at_label || last.telegram_sent_at || '—'}`;
                        lastSignalEl.title = `Последний сигнал в Telegram: ${last.label}, отправлен ${last.telegram_sent_at || '—'}`;
                        lastSignalEl.classList.remove('text-secondary', 'text-info');
                        lastSignalEl.classList.add('text-info');
                    } else {
                        lastSignalEl.textContent = '· отпр. —';
                        lastSignalEl.title = 'В Telegram по этому ТФ ещё не отправлялось';
                        lastSignalEl.classList.remove('text-info');
                        lastSignalEl.classList.add('text-secondary');
                    }
                }
                if (meta) {
                    meta.textContent = candles.length
                        ? `${candles.length} баров (все загруженные)`
                        : 'нет данных — запустите fetch_candles';
                }
                if (label === 'M1' && candles.length) {
                    lastClose = candles[candles.length - 1].close;
                }
            });

            if (priceSelector && lastClose !== null) {
                const priceEl = document.querySelector(priceSelector);
                if (priceEl) {
                    priceEl.textContent = lastClose.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                }
            }
        }

        return { load, setMaEnabled, isMaEnabled, setPcEnabled, isPcEnabled };
    }

    function createSingleChart({ endpoint, containerId, viewKey }) {
        const container = document.getElementById(containerId);
        if (!container) {
            return {
                load: async () => {},
                setMaEnabled: () => false,
                isMaEnabled: () => false,
                setPcEnabled: () => false,
                isPcEnabled: () => false,
            };
        }
        const entry = createChart(container);
        entry.view = bindViewPersistence(
            entry.chart,
            entry.series,
            entry.container,
            viewKey || `single:${containerId}`
        );
        let maEnabled = readMaEnabled();
        let pcEnabled = readPcEnabled();
        entry.setMaEnabled(maEnabled);
        setMaLegendVisible(entry.container, maEnabled);
        entry.setPcEnabled(pcEnabled);
        setPcLegendVisible(entry.container, pcEnabled);

        async function load() {
            const response = await fetch(endpoint, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Не удалось загрузить свечи.');
            }
            const payload = await response.json();
            const candles = payload.candles || [];
            entry.view.setCandles(candles);
            entry.setLastCandles(candles);

            const lastSignalEl = document.getElementById('chart-last-signal');
            if (lastSignalEl) {
                const last = payload.last_signal;
                if (last && last.label) {
                    lastSignalEl.textContent = `· отпр. ${last.label} @ ${last.sent_at_label || last.telegram_sent_at || '—'}`;
                    lastSignalEl.title = `Последний сигнал в Telegram: ${last.label}, отправлен ${last.telegram_sent_at || '—'}`;
                    lastSignalEl.classList.remove('text-secondary');
                    lastSignalEl.classList.add('text-info');
                } else {
                    lastSignalEl.textContent = '· отпр. —';
                    lastSignalEl.title = 'В Telegram по этому ТФ ещё не отправлялось';
                    lastSignalEl.classList.remove('text-info');
                    lastSignalEl.classList.add('text-secondary');
                }
            }

            return candles.length;
        }

        function setMaEnabled(enabled) {
            maEnabled = !!enabled;
            writeMaEnabled(maEnabled);
            entry.setMaEnabled(maEnabled);
            setMaLegendVisible(entry.container, maEnabled);
            return maEnabled;
        }

        function isMaEnabled() {
            return maEnabled;
        }

        function setPcEnabled(enabled) {
            pcEnabled = !!enabled;
            writePcEnabled(pcEnabled);
            entry.setPcEnabled(pcEnabled);
            setPcLegendVisible(entry.container, pcEnabled);
            return pcEnabled;
        }

        function isPcEnabled() {
            return pcEnabled;
        }

        return { load, setMaEnabled, isMaEnabled, setPcEnabled, isPcEnabled };
    }

    function createQuotesAutoRefresh({
        refreshEndpoint,
        csrfToken,
        statusSelector,
        intervalMs = 60_000,
        onAfterRefresh,
    }) {
        let busy = false;
        let timerId = null;
        const statusEl = statusSelector ? document.querySelector(statusSelector) : null;

        const setStatus = (text, tone = 'secondary') => {
            if (!statusEl) {
                return;
            }
            statusEl.className = `badge text-bg-${tone}`;
            statusEl.textContent = text;
        };

        async function tick(manual = false) {
            if (busy) {
                return;
            }
            busy = true;
            setStatus(manual ? 'обновление…' : 'синхронизация…', 'info');
            try {
                const response = await fetch(refreshEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `csrf_token=${encodeURIComponent(csrfToken)}`,
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Ошибка обновления котировок');
                }
                if (typeof onAfterRefresh === 'function') {
                    await onAfterRefresh(payload);
                }
                const now = new Date();
                const stamp = now.toLocaleTimeString('ru-RU', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });
                setStatus(`обновлено ${stamp} · след. через 60с`, 'success');
            } catch (error) {
                setStatus(`ошибка обновления`, 'danger');
                console.error(error);
            } finally {
                busy = false;
            }
        }

        function start() {
            tick(true);
            timerId = window.setInterval(() => tick(false), intervalMs);
        }

        function stop() {
            if (timerId !== null) {
                window.clearInterval(timerId);
                timerId = null;
            }
        }

        return { start, stop, tick };
    }

    function createCandlesRepair({
        repairEndpoint,
        csrfToken,
        statusSelector,
        onAfterRepair,
    }) {
        let busy = false;
        const statusEl = statusSelector ? document.querySelector(statusSelector) : null;

        const setStatus = (text, tone = 'secondary') => {
            if (!statusEl) {
                return;
            }
            statusEl.className = `badge text-bg-${tone}`;
            statusEl.textContent = text;
        };

        async function repair() {
            if (busy) {
                return null;
            }
            busy = true;
            setStatus('проверка разрывов…', 'info');
            try {
                const response = await fetch(repairEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `csrf_token=${encodeURIComponent(csrfToken)}`,
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Ошибка догрузки котировок');
                }
                if (typeof onAfterRepair === 'function') {
                    await onAfterRepair(payload);
                }
                const gaps = Number(payload.total_gaps ?? 0);
                const saved = Number(payload.total_saved ?? 0);
                const now = new Date();
                const stamp = now.toLocaleTimeString('ru-RU', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });
                if (gaps === 0) {
                    setStatus(`разрывов нет · ${stamp}`, 'success');
                } else {
                    setStatus(`догружено ${saved} бар · ${gaps} разр. · ${stamp}`, 'success');
                }
                return payload;
            } catch (error) {
                setStatus('ошибка догрузки', 'danger');
                console.error(error);
                throw error;
            } finally {
                busy = false;
            }
        }

        return { repair };
    }

    window.TradeSignalsCharts = {
        createDashboard,
        createSingleChart,
        createQuotesAutoRefresh,
        createCandlesRepair,
        persistChartTimeframe,
        readSavedChartTimeframe,
    };
})();

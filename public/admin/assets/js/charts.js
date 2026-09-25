(() => {
    'use strict';

    const RIGHT_OFFSET_BARS = 300;
    const VIEW_STORAGE_KEY = 'tradesignals.chartView.v2';
    const MA_STORAGE_KEY = 'tradesignals.chartMa.v2';
    const PC_STORAGE_KEY = 'tradesignals.chartPc.v2';
    const SEQ_STORAGE_KEY = 'tradesignals.chartSeq.v1';
    const SEQ_MIN_BARS = 4;
    const TF_STORAGE_KEY = 'tradesignals.chartTf.v1';
    const TF_COOKIE = 'tradesignals_chart_tf';
    const MA_PERIODS = [
        { period: 28, color: '#3b82f6', key: 'ma28' },
    ];
    /** Параметры как в Pine «Price Channel + Trend Flip». */
    const PC_LENGTH = 20;

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

    function readIndicatorMap(storageKey) {
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) {
                return {};
            }
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_error) {
            return {};
        }
    }

    function writeIndicatorMap(storageKey, map) {
        try {
            localStorage.setItem(storageKey, JSON.stringify(map));
        } catch (_error) {
            // ignore
        }
    }

    function readMaEnabled(timeframe) {
        if (!timeframe) {
            return false;
        }
        const map = readIndicatorMap(MA_STORAGE_KEY);
        return map[timeframe] === '1';
    }

    function writeMaEnabled(timeframe, enabled) {
        if (!timeframe) {
            return;
        }
        const map = readIndicatorMap(MA_STORAGE_KEY);
        map[timeframe] = enabled ? '1' : '0';
        writeIndicatorMap(MA_STORAGE_KEY, map);
    }

    function readPcEnabled(timeframe) {
        if (!timeframe) {
            return false;
        }
        const map = readIndicatorMap(PC_STORAGE_KEY);
        return map[timeframe] === '1';
    }

    function writePcEnabled(timeframe, enabled) {
        if (!timeframe) {
            return;
        }
        const map = readIndicatorMap(PC_STORAGE_KEY);
        map[timeframe] = enabled ? '1' : '0';
        writeIndicatorMap(PC_STORAGE_KEY, map);
    }

    function readSeqEnabled(timeframe) {
        if (!timeframe) {
            return false;
        }
        const map = readIndicatorMap(SEQ_STORAGE_KEY);
        return map[timeframe] === '1';
    }

    function writeSeqEnabled(timeframe, enabled) {
        if (!timeframe) {
            return;
        }
        const map = readIndicatorMap(SEQ_STORAGE_KEY);
        map[timeframe] = enabled ? '1' : '0';
        writeIndicatorMap(SEQ_STORAGE_KEY, map);
    }

    /**
     * Серии из 4+ свечей подряд в одну сторону.
     * Пустое тело (open == close) рвёт серию и само не входит в неё.
     * Если серия длиннее 4 — выделяется целиком.
     */
    function sequenceRunIndices(candles, minBars = SEQ_MIN_BARS) {
        const marked = [];
        if (!Array.isArray(candles) || candles.length === 0) {
            return marked;
        }

        const directionOf = (candle) => {
            const open = Number(candle.open);
            const close = Number(candle.close);
            if (!Number.isFinite(open) || !Number.isFinite(close) || Math.abs(close - open) < 1e-8) {
                return null;
            }
            return close > open ? 'up' : 'down';
        };

        let runStart = 0;
        let runDir = null;
        const flush = (endExclusive) => {
            if (runDir !== null && endExclusive - runStart >= minBars) {
                for (let i = runStart; i < endExclusive; i += 1) {
                    marked.push(i);
                }
            }
        };

        for (let i = 0; i < candles.length; i += 1) {
            const dir = directionOf(candles[i]);
            if (dir === null) {
                flush(i);
                runDir = null;
                runStart = i + 1;
                continue;
            }
            if (runDir === null) {
                runDir = dir;
                runStart = i;
                continue;
            }
            if (dir !== runDir) {
                flush(i);
                runDir = dir;
                runStart = i;
            }
        }
        flush(candles.length);
        return marked;
    }

    function paintSequenceCandles(candles, enabled) {
        const list = Array.isArray(candles) ? candles : [];
        const marked = enabled ? new Set(sequenceRunIndices(list)) : new Set();
        return list.map((candle, index) => {
            const item = {
                time: candle.time,
                open: Number(candle.open),
                high: Number(candle.high),
                low: Number(candle.low),
                close: Number(candle.close),
            };
            if (!marked.has(index)) {
                if (!enabled) {
                    return item;
                }
                const plain = item.close >= item.open;
                return {
                    ...item,
                    borderColor: plain ? '#22c55e' : '#ef4444',
                };
            }
            const up = item.close >= item.open;
            return {
                ...item,
                color: up ? '#15803d' : '#b91c1c',
                borderColor: '#f8fafc',
                wickColor: up ? '#bbf7d0' : '#fecaca',
            };
        });
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

    /** Trend Flip (Pine): стрелки разворота. */
    function computeTrendFlip(candles) {
        const markers = [];
        if (!Array.isArray(candles) || candles.length === 0) {
            return markers;
        }

        let trend = null;
        let lastFlipClose = null;
        let extremeOpen = null;
        let extremeClose = null;

        for (let i = 0; i < candles.length; i += 1) {
            const open = Number(candles[i].open);
            const close = Number(candles[i].close);
            let flip = false;

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
                } else {
                    markers.push({
                        time: candles[i].time,
                        position: 'aboveBar',
                        color: '#22c55e',
                        shape: 'arrowDown',
                        text: delta > 0 ? `↓ ${Math.round(delta)}` : '↓',
                    });
                }
                lastFlipClose = close;
            }
        }

        return markers;
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

    function createChart(container, options = {}) {
        const enableDgDrag = !!options.enableDgDrag;
        const onDgLevelsCommit = typeof options.onDgLevelsCommit === 'function'
            ? options.onDgLevelsCommit
            : null;

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
        let seqEnabled = false;
        let publishCandles = null;
        const dgPriceLines = [];
        let dgLinesMeta = [];
        let dgOverlayState = null;
        let dgDragging = false;
        let dgDragMeta = null;
        let dgSaveTimer = null;
        let dgScrollBackup = null;

        const clearDgPriceLines = () => {
            while (dgPriceLines.length > 0) {
                const line = dgPriceLines.pop();
                try {
                    series.removePriceLine(line);
                } catch (_error) {
                    // ignore
                }
            }
            dgLinesMeta = [];
        };

        const formatDgTitle = (role, index, price) => {
            if (!dgOverlayState || !Number.isFinite(dgOverlayState.anchor) || !Number.isFinite(price)) {
                if (role === 'level') {
                    return `L${index + 1}`;
                }
                return role.toUpperCase();
            }
            const anchor = Number(dgOverlayState.anchor);
            const offset = Math.max(0, Math.abs(price - anchor));
            const offsetLabel = Math.round(offset);
            if (role === 'level') {
                return `L${index + 1} ${offsetLabel}`;
            }
            if (role === 'tp') {
                return `TP ${offsetLabel}`;
            }
            if (role === 'sl') {
                return `SL ${offsetLabel}`;
            }
            return role.toUpperCase();
        };

        const setDgOverlay = (overlay) => {
            if (dgDragging) {
                return;
            }
            clearDgPriceLines();
            dgOverlayState = overlay && overlay.show_h1 ? overlay : null;
            if (!dgOverlayState) {
                return;
            }

            const addLine = (price, color, title, style, meta) => {
                if (!Number.isFinite(price)) {
                    return;
                }
                const line = series.createPriceLine({
                    price,
                    color,
                    lineWidth: meta && meta.draggable ? 3 : 2,
                    lineStyle: style,
                    axisLabelVisible: true,
                    title,
                });
                dgPriceLines.push(line);
                if (meta) {
                    dgLinesMeta.push({ ...meta, line, color, style });
                }
            };

            if (dgOverlayState.anchor != null) {
                addLine(
                    Number(dgOverlayState.anchor),
                    '#38bdf8',
                    dgOverlayState.mode === 'low' ? 'Low' : 'High',
                    LightweightCharts.LineStyle.Dashed,
                    { role: 'anchor', draggable: false }
                );
            }
            const levelColors = ['#fbbf24', '#f59e0b', '#d97706'];
            (dgOverlayState.levels || []).forEach((lvl) => {
                const idx = Number(lvl.index ?? 0);
                const price = Number(lvl.price);
                addLine(
                    price,
                    levelColors[idx] || '#fbbf24',
                    formatDgTitle('level', idx, price),
                    LightweightCharts.LineStyle.Solid,
                    { role: 'level', index: idx, draggable: enableDgDrag }
                );
            });
            if (dgOverlayState.tp != null) {
                const price = Number(dgOverlayState.tp);
                addLine(
                    price,
                    '#22c55e',
                    formatDgTitle('tp', 0, price),
                    LightweightCharts.LineStyle.Dotted,
                    { role: 'tp', draggable: enableDgDrag }
                );
            }
            if (dgOverlayState.sl != null) {
                const price = Number(dgOverlayState.sl);
                addLine(
                    price,
                    '#ef4444',
                    formatDgTitle('sl', 0, price),
                    LightweightCharts.LineStyle.Dotted,
                    { role: 'sl', draggable: enableDgDrag }
                );
            }
        };

        const clampDragPrice = (role, rawPrice) => {
            if (!dgOverlayState || !Number.isFinite(dgOverlayState.anchor)) {
                return rawPrice;
            }
            const anchor = Number(dgOverlayState.anchor);
            const mode = dgOverlayState.mode === 'low' ? 'low' : 'high';
            if (role === 'level' || role === 'sl') {
                if (mode === 'high') {
                    return Math.min(rawPrice, anchor - 0.01);
                }
                return Math.max(rawPrice, anchor + 0.01);
            }
            if (role === 'tp') {
                if (mode === 'high') {
                    return Math.max(rawPrice, anchor + 0.01);
                }
                return Math.min(rawPrice, anchor - 0.01);
            }
            return rawPrice;
        };

        const collectDgCommitPayload = () => {
            if (!dgOverlayState || !Number.isFinite(dgOverlayState.anchor)) {
                return null;
            }
            const anchor = Number(dgOverlayState.anchor);
            const mode = dgOverlayState.mode === 'low' ? 'low' : 'high';
            const levels = [null, null, null];
            let profit = null;
            let stop = null;
            dgLinesMeta.forEach((meta) => {
                const price = Number(meta.line.options().price);
                if (!Number.isFinite(price)) {
                    return;
                }
                const offset = Math.max(0.01, Math.abs(price - anchor));
                if (meta.role === 'level' && meta.index >= 0 && meta.index < 3) {
                    // Для high — уровень ниже хая; для low — выше лоя.
                    const levelOffset = Math.max(
                        0.01,
                        mode === 'low' ? price - anchor : anchor - price
                    );
                    levels[meta.index] = { offset: levelOffset };
                } else if (meta.role === 'tp') {
                    profit = offset;
                } else if (meta.role === 'sl') {
                    stop = offset;
                }
            });
            if (levels.some((row) => row === null)) {
                return null;
            }
            return { levels, profit, stop };
        };

        const commitDgDrag = () => {
            const payload = collectDgCommitPayload();
            if (!payload || !onDgLevelsCommit) {
                return;
            }
            if (dgSaveTimer !== null) {
                window.clearTimeout(dgSaveTimer);
            }
            dgSaveTimer = window.setTimeout(() => {
                dgSaveTimer = null;
                Promise.resolve(onDgLevelsCommit(payload))
                    .then((result) => {
                        if (result && result.direction_grid) {
                            setDgOverlay(result.direction_grid);
                        }
                    })
                    .catch((error) => {
                        console.error(error);
                    });
            }, 250);
        };

        const hitDgLine = (y) => {
            let best = null;
            let bestDist = 8;
            dgLinesMeta.forEach((meta) => {
                if (!meta.draggable) {
                    return;
                }
                const price = Number(meta.line.options().price);
                const coord = series.priceToCoordinate(price);
                if (coord == null) {
                    return;
                }
                const dist = Math.abs(coord - y);
                if (dist <= bestDist) {
                    bestDist = dist;
                    best = meta;
                }
            });
            return best;
        };

        if (enableDgDrag) {
            container.style.touchAction = 'none';
            container.addEventListener('pointerdown', (event) => {
                if (event.button !== 0 || !dgOverlayState) {
                    return;
                }
                const rect = container.getBoundingClientRect();
                const y = event.clientY - rect.top;
                const hit = hitDgLine(y);
                if (!hit) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                dgDragging = true;
                dgDragMeta = hit;
                container.setPointerCapture(event.pointerId);
                container.style.cursor = 'ns-resize';
                dgScrollBackup = {
                    vertTouchDrag: true,
                    pressedMouseMove: true,
                };
                chart.applyOptions({
                    handleScroll: {
                        vertTouchDrag: false,
                        pressedMouseMove: false,
                    },
                    handleScale: {
                        axisPressedMouseMove: { time: true, price: false },
                    },
                });
            });

            container.addEventListener('pointermove', (event) => {
                if (!dgDragging || !dgDragMeta) {
                    if (!dgOverlayState) {
                        return;
                    }
                    const rect = container.getBoundingClientRect();
                    const y = event.clientY - rect.top;
                    container.style.cursor = hitDgLine(y) ? 'ns-resize' : '';
                    return;
                }
                const rect = container.getBoundingClientRect();
                const y = event.clientY - rect.top;
                const rawPrice = series.coordinateToPrice(y);
                if (rawPrice == null || !Number.isFinite(rawPrice)) {
                    return;
                }
                const price = clampDragPrice(dgDragMeta.role, Number(rawPrice));
                dgDragMeta.line.applyOptions({
                    price,
                    title: formatDgTitle(dgDragMeta.role, dgDragMeta.index || 0, price),
                });
            });

            const endDrag = (event) => {
                if (!dgDragging) {
                    return;
                }
                dgDragging = false;
                dgDragMeta = null;
                container.style.cursor = '';
                try {
                    container.releasePointerCapture(event.pointerId);
                } catch (_error) {
                    // ignore
                }
                chart.applyOptions({
                    handleScroll: {
                        vertTouchDrag: true,
                        pressedMouseMove: true,
                    },
                    handleScale: {
                        axisPressedMouseMove: { time: true, price: true },
                    },
                });
                dgScrollBackup = null;
                commitDgDrag();
            };

            container.addEventListener('pointerup', endDrag);
            container.addEventListener('pointercancel', endDrag);
        }

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
            if (!pcEnabled || lastCandles.length === 0) {
                try {
                    series.setMarkers([]);
                } catch (_error) {
                    // ignore
                }
                return;
            }

            const pcMarkers = computePriceChannelMarkers(lastCandles, PC_LENGTH);
            const flipMarkers = computeTrendFlip(lastCandles);
            series.setMarkers(mergeMarkers(pcMarkers, flipMarkers));
        };

        const setMaEnabled = (enabled) => {
            maEnabled = !!enabled;
            applyMaData();
        };

        const setPcEnabled = (enabled) => {
            pcEnabled = !!enabled;
            applyPcData();
        };

        const setSeqEnabled = (enabled) => {
            seqEnabled = !!enabled;
            series.applyOptions({
                borderVisible: seqEnabled,
                borderUpColor: '#22c55e',
                borderDownColor: '#ef4444',
            });
            if (typeof publishCandles === 'function' && lastCandles.length > 0) {
                publishCandles(paintSequenceCandles(lastCandles, seqEnabled), true);
            }
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
            setSeqEnabled,
            setPublishCandles(fn) {
                publishCandles = typeof fn === 'function' ? fn : null;
            },
            setDgOverlay,
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
        function setCandles(candles, replaceAll = false) {
            lock();

            const preferred = state || loadPersistedView(viewKey);
            const prevCount = barCount;
            const nextCount = candles.length;
            const chartCandles = candles.map((candle) => {
                const item = {
                    time: candle.time,
                    open: candle.open,
                    high: candle.high,
                    low: candle.low,
                    close: candle.close,
                };
                if (candle.color) {
                    item.color = candle.color;
                }
                if (candle.borderColor) {
                    item.borderColor = candle.borderColor;
                }
                if (candle.wickColor) {
                    item.wickColor = candle.wickColor;
                }
                return item;
            });
            const usedIncremental = !replaceAll && pushCandlesIncremental(series, prevCount, chartCandles);

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

    function createDashboard({ endpoint, containerSelector, priceSelector, csrfToken }) {
        const hosts = Array.from(document.querySelectorAll(containerSelector));
        const charts = new Map();

        async function saveDgLevels(payload) {
            if (!csrfToken) {
                throw new Error('Нет CSRF для сохранения уровней.');
            }
            const response = await fetch('/api/direction_grid_levels.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    levels: payload.levels,
                    profit: payload.profit,
                    stop: payload.stop,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Не удалось сохранить уровни.');
            }
            return data;
        }

        hosts.forEach((host) => {
            const label = host.dataset.interval;
            const entry = createChart(host, {
                enableDgDrag: label === 'H1',
                onDgLevelsCommit: label === 'H1' ? saveDgLevels : null,
            });
            entry.view = bindViewPersistence(
                entry.chart,
                entry.series,
                entry.container,
                `dashboard:${label}`
            );
            const maEnabled = readMaEnabled(label);
            const pcEnabled = readPcEnabled(label);
            const seqEnabled = readSeqEnabled(label);
            entry.setPublishCandles((painted, replaceAll) => entry.view.setCandles(painted, replaceAll));
            entry.setMaEnabled(maEnabled);
            setMaLegendVisible(entry.container, maEnabled);
            entry.setPcEnabled(pcEnabled);
            setPcLegendVisible(entry.container, pcEnabled);
            entry.setSeqEnabled(seqEnabled);
            charts.set(label, entry);
        });

        function setMaEnabled(timeframe, enabled) {
            const tf = String(timeframe || '');
            const on = !!enabled;
            writeMaEnabled(tf, on);
            const entry = charts.get(tf);
            if (entry) {
                entry.setMaEnabled(on);
                setMaLegendVisible(entry.container, on);
            }
            return on;
        }

        function isMaEnabled(timeframe) {
            return readMaEnabled(String(timeframe || ''));
        }

        function setPcEnabled(timeframe, enabled) {
            const tf = String(timeframe || '');
            const on = !!enabled;
            writePcEnabled(tf, on);
            const entry = charts.get(tf);
            if (entry) {
                entry.setPcEnabled(on);
                setPcLegendVisible(entry.container, on);
            }
            return on;
        }

        function isPcEnabled(timeframe) {
            return readPcEnabled(String(timeframe || ''));
        }

        function setSeqEnabled(timeframe, enabled) {
            const tf = String(timeframe || '');
            const on = !!enabled;
            writeSeqEnabled(tf, on);
            const entry = charts.get(tf);
            if (entry) {
                entry.setSeqEnabled(on);
            }
            return on;
        }

        function isSeqEnabled(timeframe) {
            return readSeqEnabled(String(timeframe || ''));
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
                entry.setLastCandles(candles);
                entry.view.setCandles(
                    paintSequenceCandles(candles, isSeqEnabled(label)),
                    isSeqEnabled(label)
                );
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

            const h1 = charts.get('H1');
            if (h1 && typeof h1.setDgOverlay === 'function') {
                h1.setDgOverlay(payload.direction_grid || null);
            }
        }

        return {
            load,
            setMaEnabled,
            isMaEnabled,
            setPcEnabled,
            isPcEnabled,
            setSeqEnabled,
            isSeqEnabled,
        };
    }

    function createSingleChart({ endpoint, containerId, viewKey, interval }) {
        const container = document.getElementById(containerId);
        const timeframe = String(interval || '');
        if (!container) {
            return {
                load: async () => {},
                setMaEnabled: () => false,
                isMaEnabled: () => false,
                setPcEnabled: () => false,
                isPcEnabled: () => false,
                setSeqEnabled: () => false,
                isSeqEnabled: () => false,
            };
        }
        const entry = createChart(container);
        entry.view = bindViewPersistence(
            entry.chart,
            entry.series,
            entry.container,
            viewKey || `single:${containerId}`
        );
        let maEnabled = readMaEnabled(timeframe);
        let pcEnabled = readPcEnabled(timeframe);
        let seqEnabled = readSeqEnabled(timeframe);
        entry.setPublishCandles((painted, replaceAll) => entry.view.setCandles(painted, replaceAll));
        entry.setMaEnabled(maEnabled);
        setMaLegendVisible(entry.container, maEnabled);
        entry.setPcEnabled(pcEnabled);
        setPcLegendVisible(entry.container, pcEnabled);
        entry.setSeqEnabled(seqEnabled);

        async function load() {
            const response = await fetch(endpoint, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Не удалось загрузить свечи.');
            }
            const payload = await response.json();
            const candles = payload.candles || [];
            entry.setLastCandles(candles);
            entry.view.setCandles(paintSequenceCandles(candles, seqEnabled), seqEnabled);

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
            writeMaEnabled(timeframe, maEnabled);
            entry.setMaEnabled(maEnabled);
            setMaLegendVisible(entry.container, maEnabled);
            return maEnabled;
        }

        function isMaEnabled() {
            return maEnabled;
        }

        function setPcEnabled(enabled) {
            pcEnabled = !!enabled;
            writePcEnabled(timeframe, pcEnabled);
            entry.setPcEnabled(pcEnabled);
            setPcLegendVisible(entry.container, pcEnabled);
            return pcEnabled;
        }

        function isPcEnabled() {
            return pcEnabled;
        }

        function setSeqEnabled(enabled) {
            seqEnabled = !!enabled;
            writeSeqEnabled(timeframe, seqEnabled);
            entry.setSeqEnabled(seqEnabled);
            return seqEnabled;
        }

        function isSeqEnabled() {
            return seqEnabled;
        }

        return { load, setMaEnabled, isMaEnabled, setPcEnabled, isPcEnabled, setSeqEnabled, isSeqEnabled };
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
                let payload = null;
                try {
                    payload = await response.json();
                } catch (_parseError) {
                    throw new Error(`HTTP ${response.status}: ответ не JSON`);
                }
                if (!response.ok || !payload.ok) {
                    throw new Error(payload?.error || `HTTP ${response.status}`);
                }
                if (typeof onAfterRepair === 'function') {
                    await onAfterRepair(payload);
                }
                const gaps = Number(payload.total_gaps ?? 0);
                const saved = Number(payload.total_saved ?? 0);
                const warnCount = payload.errors ? Object.keys(payload.errors).length : 0;
                const now = new Date();
                const stamp = now.toLocaleTimeString('ru-RU', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });
                if (warnCount > 0) {
                    setStatus(`частично: +${saved} бар · ${stamp}`, 'warning');
                } else if (gaps === 0) {
                    setStatus(`разрывов нет · ${stamp}`, 'success');
                } else {
                    setStatus(`догружено ${saved} бар · ${gaps} разр. · ${stamp}`, 'success');
                }
                return payload;
            } catch (error) {
                const msg = error instanceof Error ? error.message : 'ошибка догрузки';
                const short = msg.length > 48 ? `${msg.slice(0, 45)}…` : msg;
                setStatus(short, 'danger');
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

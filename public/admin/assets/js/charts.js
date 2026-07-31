(() => {
    'use strict';

    const RIGHT_OFFSET_BARS = 300;
    const VIEW_STORAGE_KEY = 'tradesignals.chartView.v2';

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

        const resize = () => {
            chart.applyOptions({
                width: container.clientWidth,
                height: container.clientHeight,
            });
        };
        resize();
        window.addEventListener('resize', resize);

        attachChartNav(container, chart);

        return { chart, series, resize, container };
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

        chart.timeScale().subscribeVisibleLogicalRangeChange(scheduleSave);
        container.addEventListener('mouseup', scheduleSave);
        container.addEventListener('touchend', scheduleSave, { passive: true });
        container.addEventListener('wheel', scheduleSave, { passive: true });

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

        hosts.forEach((host) => {
            const entry = createChart(host);
            const label = host.dataset.interval;
            entry.view = bindViewPersistence(
                entry.chart,
                entry.series,
                entry.container,
                `dashboard:${label}`
            );
            charts.set(label, entry);
        });

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
                if (!entry) {
                    return;
                }
                const candles = item.candles || [];
                entry.view.setCandles(candles);
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

        return { load };
    }

    function createSingleChart({ endpoint, containerId, viewKey }) {
        const container = document.getElementById(containerId);
        if (!container) {
            return { load: async () => {} };
        }
        const entry = createChart(container);
        entry.view = bindViewPersistence(
            entry.chart,
            entry.series,
            entry.container,
            viewKey || `single:${containerId}`
        );

        async function load() {
            const response = await fetch(endpoint, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Не удалось загрузить свечи.');
            }
            const payload = await response.json();
            const candles = payload.candles || [];
            entry.view.setCandles(candles);
            return candles.length;
        }

        return { load };
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

    window.TradeSignalsCharts = { createDashboard, createSingleChart, createQuotesAutoRefresh };
})();

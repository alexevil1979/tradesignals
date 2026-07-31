(() => {
    'use strict';

    const RIGHT_OFFSET_BARS = 300;
    const VIEW_STORAGE_KEY = 'tradesignals.chartView.v1';

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

        return { chart, series, resize, container };
    }

    function applyRightOffset(chart, offsetBars = RIGHT_OFFSET_BARS) {
        const ts = chart.timeScale();
        ts.applyOptions({ rightOffset: offsetBars });
        ts.fitContent();
        const range = ts.getVisibleLogicalRange();
        if (range) {
            ts.setVisibleLogicalRange({
                from: range.from,
                to: range.to + offsetBars,
            });
        }
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
            // ignore quota / private mode
        }
    }

    function loadPersistedView(viewKey) {
        const state = readViewStore()[viewKey];
        if (!state || !state.time || state.time.from == null || state.time.to == null) {
            return null;
        }
        return state;
    }

    function persistView(viewKey, state) {
        if (!viewKey || !state?.time) {
            return;
        }
        const store = readViewStore();
        store[viewKey] = state;
        writeViewStore(store);
    }

    function captureView(chart, series) {
        const time = chart.timeScale().getVisibleRange();
        if (!time || time.from == null || time.to == null) {
            return null;
        }
        let price = null;
        try {
            const visiblePrice = series.priceScale().getVisibleRange();
            if (visiblePrice && visiblePrice.from != null && visiblePrice.to != null) {
                price = { from: visiblePrice.from, to: visiblePrice.to };
            }
        } catch (_error) {
            price = null;
        }
        return {
            time: { from: time.from, to: time.to },
            price,
        };
    }

    function restoreView(chart, series, state) {
        if (!state?.time) {
            return false;
        }
        try {
            chart.timeScale().setVisibleRange({
                from: state.time.from,
                to: state.time.to,
            });
            if (state.price && state.price.from != null && state.price.to != null) {
                series.priceScale().setVisibleRange({
                    from: state.price.from,
                    to: state.price.to,
                });
            }
            return true;
        } catch (_error) {
            return false;
        }
    }

    /**
     * Сохраняет зум/сдвиг в localStorage и восстанавливает их
     * после обновления свечей и после перезагрузки страницы.
     */
    function bindViewPersistence(chart, series, container, viewKey) {
        let saveTimer = null;
        let applying = false;
        let state = loadPersistedView(viewKey);

        const scheduleSave = () => {
            if (applying || !viewKey) {
                return;
            }
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(() => {
                const next = captureView(chart, series);
                if (!next) {
                    return;
                }
                state = next;
                persistView(viewKey, next);
            }, 120);
        };

        chart.timeScale().subscribeVisibleTimeRangeChange(scheduleSave);
        container.addEventListener('mouseup', scheduleSave);
        container.addEventListener('touchend', scheduleSave, { passive: true });
        container.addEventListener('wheel', scheduleSave, { passive: true });

        return {
            applyAfterData() {
                applying = true;
                window.clearTimeout(saveTimer);

                const preferred = state || loadPersistedView(viewKey);
                const done = () => {
                    window.requestAnimationFrame(() => {
                        applying = false;
                    });
                };

                window.requestAnimationFrame(() => {
                    let restored = false;
                    if (preferred) {
                        restored = restoreView(chart, series, preferred);
                    }
                    if (!restored) {
                        applyRightOffset(chart);
                    }
                    const current = captureView(chart, series);
                    if (current) {
                        state = current;
                        if (!preferred) {
                            persistView(viewKey, current);
                        }
                    } else if (preferred) {
                        state = preferred;
                    }
                    done();
                });
            },
        };
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
                if (!entry) {
                    return;
                }
                const candles = item.candles || [];
                entry.series.setData(candles);
                entry.view.applyAfterData();
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
            entry.series.setData(candles);
            entry.view.applyAfterData();
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

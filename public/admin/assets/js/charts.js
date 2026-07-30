(() => {
    'use strict';

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

        return { chart, series, resize };
    }

    function createDashboard({ endpoint, containerSelector, priceSelector }) {
        const hosts = Array.from(document.querySelectorAll(containerSelector));
        const charts = new Map();

        hosts.forEach((host) => {
            charts.set(host.dataset.interval, createChart(host));
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
                entry.chart.timeScale().fitContent();
                if (meta) {
                    meta.textContent = candles.length
                        ? `${candles.length} баров`
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

    function createSingleChart({ endpoint, containerId }) {
        const container = document.getElementById(containerId);
        if (!container) {
            return { load: async () => {} };
        }
        const entry = createChart(container);

        async function load() {
            const response = await fetch(endpoint, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Не удалось загрузить свечи.');
            }
            const payload = await response.json();
            const candles = payload.candles || [];
            entry.series.setData(candles);
            entry.chart.timeScale().fitContent();
            return candles.length;
        }

        return { load };
    }

    window.TradeSignalsCharts = { createDashboard, createSingleChart };
})();

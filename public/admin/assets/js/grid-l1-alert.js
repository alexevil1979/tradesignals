(() => {
    'use strict';

    const ENDPOINT = '/api/direction_grid_alert.php';
    const POLL_MS = 5_000;
    const BEEP_EVERY_MS = 10_000;
    const MAX_BEEPS = 20;

    let pollTimer = null;
    let beepTimer = null;
    let beepCount = 0;
    let active = false;
    let audioCtx = null;

    function ensureAudio() {
        if (audioCtx) {
            return audioCtx;
        }
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            return null;
        }
        audioCtx = new Ctx();
        return audioCtx;
    }

    /** Мягкий короткий бип (~660 Гц). */
    function playBeep() {
        const ctx = ensureAudio();
        if (!ctx) {
            return;
        }
        if (ctx.state === 'suspended') {
            ctx.resume().catch(() => {});
        }

        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(660, now);
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.22);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(now);
        osc.stop(now + 0.25);
    }

    function stopBeeping() {
        active = false;
        beepCount = 0;
        if (beepTimer !== null) {
            window.clearInterval(beepTimer);
            beepTimer = null;
        }
    }

    function startBeeping() {
        if (active) {
            return;
        }
        active = true;
        beepCount = 0;
        playBeep();
        beepCount = 1;
        beepTimer = window.setInterval(() => {
            if (beepCount >= MAX_BEEPS) {
                stopBeeping();
                return;
            }
            playBeep();
            beepCount += 1;
        }, BEEP_EVERY_MS);
    }

    async function poll() {
        try {
            const response = await fetch(ENDPOINT, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (!payload || !payload.ok) {
                return;
            }
            if (payload.triggered) {
                startBeeping();
            } else {
                stopBeeping();
            }
        } catch (_error) {
            // ignore network blips
        }
    }

    function unlockAudioOnce() {
        const ctx = ensureAudio();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().catch(() => {});
        }
    }

    document.addEventListener('pointerdown', unlockAudioOnce, { once: true });
    document.addEventListener('keydown', unlockAudioOnce, { once: true });

    poll();
    pollTimer = window.setInterval(poll, POLL_MS);

    window.addEventListener('beforeunload', () => {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
        }
        stopBeeping();
    });
})();

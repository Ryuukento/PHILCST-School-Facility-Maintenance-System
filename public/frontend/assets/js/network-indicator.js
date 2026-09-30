(function () {
    const indicator = document.getElementById('networkSignalIndicator');
    const textNode = document.getElementById('networkSignalText');

    if (!indicator || !textNode) {
        return;
    }

    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    const CHECK_INTERVAL_MS = 15000;
    const SLOW_LATENCY_MS = 1400;

    function getHealthCheckUrl() {
        if (typeof window.SFMS_PUBLIC_URL === 'function') {
            return window.SFMS_PUBLIC_URL('/up');
        }

        return '/up';
    }

    function setIndicatorState(state, message) {
        if (state === 'good') {
            indicator.classList.remove('is-visible');
            indicator.dataset.state = 'good';
            return;
        }

        indicator.classList.add('is-visible');
        indicator.dataset.state = state;
        textNode.textContent = message;
    }

    function isWeakByConnectionApi() {
        if (!connection) {
            return false;
        }

        const effectiveType = String(connection.effectiveType || '').toLowerCase();
        const downlink = Number(connection.downlink || 0);
        const rtt = Number(connection.rtt || 0);

        if (connection.saveData === true) {
            return true;
        }

        if (effectiveType === 'slow-2g' || effectiveType === '2g' || effectiveType === '3g') {
            return true;
        }

        if (rtt > 500) {
            return true;
        }

        if (downlink > 0 && downlink < 1.2) {
            return true;
        }

        return false;
    }

    async function checkLatencyWeakness() {
        const healthUrl = getHealthCheckUrl();
        const controller = new AbortController();
        const timeoutId = window.setTimeout(function () {
            controller.abort();
        }, 6000);

        const startedAt = performance.now();

        try {
            await fetch(healthUrl, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal,
            });

            const latency = performance.now() - startedAt;
            return latency >= SLOW_LATENCY_MS;
        } catch (error) {
            return true;
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    async function evaluateNetworkState() {
        if (!navigator.onLine) {
            setIndicatorState('offline', 'Offline');
            return;
        }

        if (isWeakByConnectionApi()) {
            setIndicatorState('weak', 'Weak signal');
            return;
        }

        const slowByLatency = await checkLatencyWeakness();
        if (slowByLatency) {
            setIndicatorState('weak', 'Slow network');
            return;
        }

        setIndicatorState('good', 'Connected');
    }

    window.addEventListener('online', evaluateNetworkState);
    window.addEventListener('offline', evaluateNetworkState);

    if (connection && typeof connection.addEventListener === 'function') {
        connection.addEventListener('change', evaluateNetworkState);
    }

    evaluateNetworkState();
    window.setInterval(evaluateNetworkState, CHECK_INTERVAL_MS);
})();

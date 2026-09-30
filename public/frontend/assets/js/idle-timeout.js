/**
 * Idle-timeout auto-logout.
 *
 * Server enforces a SESSION_TIMEOUT-minute inactivity limit (see
 * public/frontend/includes/session-guard.php). This client-side layer:
 *   1. Warns the user shortly before that limit is reached, with a countdown
 *      and a "Stay signed in" button.
 *   2. Pings session-ping.php while the user is genuinely active, so the
 *      server-side timer doesn't expire mid-task on a single long-lived page.
 *   3. Redirects to the login page if the countdown reaches zero.
 */
(function () {
    if (!window.SFMS_IDLE_TIMEOUT_MS) return;

    const IDLE_TIMEOUT_MS = window.SFMS_IDLE_TIMEOUT_MS;
    const WARNING_LEAD_MS = Math.min(60 * 1000, Math.floor(IDLE_TIMEOUT_MS / 2));
    const HEARTBEAT_INTERVAL_MS = 60 * 1000;

    const modal = document.getElementById('idle-timeout-modal');
    const countdownEl = document.getElementById('idle-timeout-countdown');
    const stayBtn = document.getElementById('idle-timeout-stay');

    let lastActivityAt = Date.now();
    let warningShown = false;
    let countdownTimer = null;

    function pingKeepAlive() {
        fetch(
            window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/session-ping.php') : '/frontend/pages/session-ping.php',
            { credentials: 'include' }
        ).catch(() => {});
    }

    function hideWarning() {
        warningShown = false;
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        if (modal && window.UI && typeof UI.toggleModal === 'function') {
            UI.toggleModal('idle-timeout-modal', false);
        }
    }

    function redirectToLogin() {
        window.location.href = window.SFMS_PUBLIC_URL
            ? window.SFMS_PUBLIC_URL('/frontend/pages/index.php?session_expired=1&reason=idle')
            : '/frontend/pages/index.php?session_expired=1&reason=idle';
    }

    function showWarning() {
        if (warningShown) return;
        warningShown = true;

        let remainingMs = IDLE_TIMEOUT_MS - (Date.now() - lastActivityAt);
        if (remainingMs < 0) remainingMs = 0;

        if (modal && window.UI && typeof UI.toggleModal === 'function') {
            UI.toggleModal('idle-timeout-modal', true);
        }

        countdownTimer = setInterval(() => {
            remainingMs -= 1000;
            const seconds = Math.max(0, Math.ceil(remainingMs / 1000));
            if (countdownEl) countdownEl.textContent = String(seconds);

            if (remainingMs <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                redirectToLogin();
            }
        }, 1000);

        if (countdownEl) countdownEl.textContent = String(Math.ceil(remainingMs / 1000));
    }

    function recordActivity() {
        const now = Date.now();
        // Ignore activity while the warning modal is up; that must be
        // resolved explicitly via the "Stay signed in" button.
        if (warningShown) return;
        lastActivityAt = now;
    }

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach((evt) => {
        document.addEventListener(evt, recordActivity, { passive: true });
    });

    if (stayBtn) {
        stayBtn.addEventListener('click', () => {
            lastActivityAt = Date.now();
            hideWarning();
            pingKeepAlive();
        });
    }

    setInterval(() => {
        const idleFor = Date.now() - lastActivityAt;

        if (idleFor >= IDLE_TIMEOUT_MS - WARNING_LEAD_MS) {
            showWarning();
            return;
        }

        if (!warningShown) {
            pingKeepAlive();
        }
    }, HEARTBEAT_INTERVAL_MS);
})();

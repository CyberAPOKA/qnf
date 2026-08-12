import { registerSW } from 'virtual:pwa-register';
import { isRecPage } from './composables/rec/recPage';
import { recDiag } from './composables/rec/recDiag';

const CHECK_INTERVAL_MS = 60 * 1000;
let recSwGuardInstalled = false;

function installRecSwGuard() {
    if (recSwGuardInstalled || !('serviceWorker' in navigator)) return;
    recSwGuardInstalled = true;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!isRecPage()) return;
        recDiag('REC_SW_CONTROLLER_CHANGE', {});
    });

    // Block a waiting worker from taking control during REC (would reload the page).
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.ready.then((registration) => {
            if (!registration.waiting) return;
            registration.waiting.postMessage({ type: 'REC_SKIP_WAITING' });
        }).catch(() => {});
    }
}

if (isRecPage()) {
    installRecSwGuard();
    // Do NOT unregister SW here — iOS Safari/PWA can reload or white-screen mid-navigation.
} else {
    registerSW({
        immediate: true,
        onNeedRefresh() {
            // Never auto-reload.
        },
        onRegisteredSW(_swUrl, registration) {
            if (!registration) return;

            setInterval(() => {
                if (isRecPage()) return;
                registration.update().catch(() => {});
            }, CHECK_INTERVAL_MS);
        },
    });
}

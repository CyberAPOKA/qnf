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
        // Never reload REC while recording — Safari would kill the camera session.
    });
}

async function quietServiceWorkersOnRec() {
    if (!isRecPage() || !('serviceWorker' in navigator)) return;

    installRecSwGuard();

    try {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.map((registration) => registration.unregister()));
        recDiag('REC_SW_UNREGISTERED', { count: registrations.length });
    } catch {
        // Best-effort only; do not wipe caches (Safari white-screen risk).
    }
}

if (isRecPage()) {
    installRecSwGuard();
    void quietServiceWorkersOnRec();
} else {
    registerSW({
        immediate: true,
        onNeedRefresh() {
            // Never auto-reload; user stays on current page until they choose to refresh.
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

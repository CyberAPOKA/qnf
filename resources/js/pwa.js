import { registerSW } from 'virtual:pwa-register';

const CHECK_INTERVAL_MS = 60 * 1000;

function isRecPage() {
    return typeof window !== 'undefined' && /\/games\/\d+\/rec(?:\/|$|\?)/.test(window.location.pathname);
}

async function disableServiceWorkersOnRec() {
    if (!isRecPage() || !('serviceWorker' in navigator)) return;

    try {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.map((registration) => registration.unregister()));
    } catch {
        // Best-effort: REC must not depend on service workers.
    }
}

if (isRecPage()) {
    disableServiceWorkersOnRec();
} else {
    registerSW({
        immediate: true,
        onNeedRefresh() {
            // Never reload automatically — a SW update mid-session is disruptive.
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

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
        // Best-effort.
    }
    // Do NOT clear caches here — wiping caches on iOS mid-navigation can white-screen the tab.
}

if (isRecPage()) {
    disableServiceWorkersOnRec();
} else {
    registerSW({
        immediate: true,
        onNeedRefresh() {
            // Never reload automatically.
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

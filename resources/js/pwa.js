import { registerSW } from 'virtual:pwa-register';

const CHECK_INTERVAL_MS = 60 * 1000;

function isRecPage() {
    return typeof window !== 'undefined' && /\/games\/\d+\/rec(?:\/|$|\?)/.test(window.location.pathname);
}

function isAppleMobile() {
    if (typeof navigator === 'undefined') return false;
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
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

function quietRealtimeOnRec() {
    if (!isRecPage() || !isAppleMobile()) return;
    try {
        window.Echo?.disconnect?.();
    } catch {
        // ignore
    }
}

if (isRecPage()) {
    disableServiceWorkersOnRec();
    quietRealtimeOnRec();
    // Echo may connect after bootstrap; disconnect again shortly.
    setTimeout(quietRealtimeOnRec, 0);
    setTimeout(quietRealtimeOnRec, 500);
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

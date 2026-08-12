import { registerSW } from 'virtual:pwa-register';

const CHECK_INTERVAL_MS = 60 * 1000;

function isRecPage() {
    return typeof window !== 'undefined' && /\/games\/\d+\/rec(?:\/|$|\?)/.test(window.location.pathname);
}

registerSW({
    immediate: true,
    onNeedRefresh() {
        // Never reload automatically — a SW update mid-REC kills the camera session.
    },
    onRegisteredSW(_swUrl, registration) {
        if (!registration) return;

        setInterval(() => {
            if (isRecPage()) return;
            registration.update().catch(() => {});
        }, CHECK_INTERVAL_MS);
    },
});

const SENSITIVE_KEY = /token|csrf|cookie|password|authorization/i;

/** True when the current URL is a REC screen (pathname only). */
export function isRecPage(pathname = typeof window !== 'undefined' ? window.location.pathname : '') {
    return /^\/games\/\d+\/rec(?:\/|$)/.test(pathname || '');
}

export function isAppleMobile() {
    if (typeof navigator === 'undefined') return false;
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

export function recLog(event, data = {}) {
    if (typeof console === 'undefined' || !console.info) return;

    const safe = {};
    for (const [key, value] of Object.entries(data)) {
        if (SENSITIVE_KEY.test(key)) continue;
        safe[key] = value;
    }

    console.info(`[REC] ${event}`, { ts: Date.now(), ...safe });
}

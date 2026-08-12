/** True when the current URL is a REC screen (pathname only; query/hash ignored). */
export function isRecPage(pathname = typeof window !== 'undefined' ? window.location.pathname : '') {
    return /^\/games\/\d+\/rec(?:\/|$)/.test(pathname || '');
}

export function isAppleMobile() {
    if (typeof navigator === 'undefined') return false;
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

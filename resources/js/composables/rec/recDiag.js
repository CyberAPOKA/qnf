const SENSITIVE = /token|csrf|cookie|password|authorization/i;

export function recDiag(event, data = {}) {
    if (typeof console === 'undefined' || !console.info) return;

    const safe = {};
    for (const [key, value] of Object.entries(data)) {
        if (SENSITIVE.test(key)) continue;
        safe[key] = value;
    }

    console.info(`[REC] ${event}`, {
        ts: Date.now(),
        ...safe,
    });
}

import { computed, unref } from 'vue';

function value(signal, fallback = null) {
    const resolved = unref(signal);
    return resolved == null ? fallback : resolved;
}

export function useRecHealth(signals = {}) {
    const status = computed(() => {
        const online = value(signals.online, typeof navigator === 'undefined' ? true : navigator.onLine);
        const recording = value(signals.isRecording, false);
        const supported = value(signals.isSupported, true);
        const captureError = value(signals.captureError);
        const sessionExpired = value(signals.sessionExpired, false);
        const availableMs = Number(value(signals.availableMs, 0));
        const targetBufferMs = Math.max(1, Number(value(signals.targetBufferMs, 30_000)));
        const pendingUploads = Number(value(signals.pendingUploads, 0));
        const heartbeatFailures = Number(value(signals.heartbeatFailures, 0));
        const storageCritical = value(signals.storageCritical, false);
        const trackEnded = value(signals.trackEnded, false);

        if (!supported || sessionExpired || storageCritical || trackEnded) return 'critical';
        if (!online) return 'offline';
        if (captureError || heartbeatFailures >= 3 || pendingUploads >= 12) return 'degraded';
        if (!recording) return 'idle';
        if (availableMs < targetBufferMs) return 'warming_up';
        return 'healthy';
    });

    const label = computed(() => {
        const availableMs = Number(value(signals.availableMs, 0));
        const targetBufferMs = Math.max(1, Number(value(signals.targetBufferMs, 30_000)));
        const availableSec = Math.max(0, Math.round(availableMs / 1000));
        const targetSec = Math.max(1, Math.round(targetBufferMs / 1000));

        if (status.value === 'warming_up') {
            return `Gravando buffer ${availableSec}/${targetSec}s`;
        }

        return ({
            idle: 'Parado',
            healthy: `Pronto · ${availableSec}s`,
            degraded: 'Conexão instável',
            offline: 'Offline',
            critical: 'Atenção necessária',
        })[status.value] || status.value;
    });

    const colorClass = computed(() => ({
        idle: 'bg-gray-100 text-gray-700 border-gray-200',
        healthy: 'bg-emerald-100 text-emerald-700 border-emerald-200',
        warming_up: 'bg-amber-100 text-amber-700 border-amber-200',
        degraded: 'bg-orange-100 text-orange-700 border-orange-200',
        offline: 'bg-gray-100 text-gray-700 border-gray-200',
        critical: 'bg-red-100 text-red-700 border-red-200',
    })[status.value]);

    return { status, label, colorClass };
}

import { usePage } from '@inertiajs/vue3';

export const REC_CONFIG_DEFAULTS = Object.freeze({
    segment_seconds: 5,
    buffer_seconds: 30,
    local_retention_seconds: 180,
    post_roll_seconds: 2,
    heartbeat_seconds: 10,
    recorder_lease_seconds: 35,
    save_debounce_milliseconds: 800,
    pending_save_poll_seconds: 2,
    upload_max_concurrency: 1,
    upload_request_timeout_seconds: 120,
});

function positiveNumber(value, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

export function normalizeRecConfig(config = {}) {
    return Object.fromEntries(
        Object.entries(REC_CONFIG_DEFAULTS).map(([key, fallback]) => [
            key,
            positiveNumber(config?.[key], fallback),
        ]),
    );
}

export function useRecConfig(explicitConfig = null) {
    let pageConfig = {};

    try {
        pageConfig = usePage().props?.rec_config || {};
    } catch {
        // Allows the composable to be used in tests without an Inertia context.
    }

    return normalizeRecConfig(explicitConfig || pageConfig);
}

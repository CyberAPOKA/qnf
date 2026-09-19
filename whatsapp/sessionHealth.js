/**
 * Readiness helpers for whatsapp-web.js 1.34.6.
 *
 * The library emits `ready` only after `AuthStore.AppState.hasSynced`, and only
 * checks that `window.Store` and `window.WWebJS` exist. Sending a message calls
 * `window.WWebJS.getChat()` / `window.WWebJS.sendMessage()`, which also need
 * `window.Store.Chat` and `window.Store.WidFactory`.
 */

export const REQUIRED_SEND_API = [
    ['hasStore', 'window.Store'],
    ['hasWWebJS', 'window.WWebJS'],
    ['hasGetChat', 'window.WWebJS.getChat'],
    ['hasSendMessage', 'window.WWebJS.sendMessage'],
    ['hasStoreChat', 'window.Store.Chat'],
    ['hasWidFactory', 'window.Store.WidFactory'],
];

export const MAX_INJECT_RETRIES = 2;
export const MAX_WATCHDOG_RESTARTS = 3;
export const RESTART_COOLDOWN_MS = 120000;

/**
 * Runs inside the WhatsApp Web page via Puppeteer. Must stay closure-free.
 */
export function inspectWhatsAppRuntime() {
    const root = globalThis;
    const authState = root.AuthStore?.AppState?.state
        ?? root.Store?.AppState?.state
        ?? null;

    return {
        documentReadyState: typeof document !== 'undefined' ? document.readyState : null,
        hasRequire: typeof root.require === 'function',
        hasDebugVersion: Boolean(root.Debug?.VERSION),
        webVersion: typeof root.Debug?.VERSION === 'string' ? root.Debug.VERSION : null,
        authState: authState == null ? null : String(authState),
        hasSynced: Boolean(root.AuthStore?.AppState?.hasSynced),
        hasStore: typeof root.Store !== 'undefined',
        hasWWebJS: typeof root.WWebJS !== 'undefined',
        hasGetChat: typeof root.WWebJS?.getChat === 'function',
        hasSendMessage: typeof root.WWebJS?.sendMessage === 'function',
        hasStoreChat: Boolean(root.Store?.Chat),
        hasWidFactory: Boolean(root.Store?.WidFactory),
        hasFindOrCreateChat: Boolean(root.Store?.FindOrCreateChat),
        hasAppState: Boolean(root.Store?.AppState),
    };
}

export function interpretRuntimeSnapshot(snapshot, error = null) {
    if (error) {
        return {
            ok: false,
            missing: ['page.evaluate'],
            snapshot: snapshot || null,
            error,
        };
    }

    if (!snapshot || typeof snapshot !== 'object') {
        return {
            ok: false,
            missing: ['runtime snapshot'],
            snapshot: null,
            error: 'empty snapshot',
        };
    }

    const missing = REQUIRED_SEND_API
        .filter(([key]) => !snapshot[key])
        .map(([, label]) => label);

    return {
        ok: missing.length === 0,
        missing,
        snapshot,
        error: null,
    };
}

export function probeLogKey(probe) {
    if (!probe) {
        return 'none';
    }

    if (probe.error) {
        return `error:${probe.error}`;
    }

    if (probe.ok) {
        return 'ok';
    }

    return `missing:${(probe.missing || []).join(',')}`;
}

export function createChangeLogger(emit) {
    let lastKey = null;

    return (key, message) => {
        if (key === lastKey) {
            return false;
        }

        lastKey = key;
        emit(message);
        return true;
    };
}

export function readinessBlocker(input) {
    if (input.shuttingDown) {
        return 'shutting down';
    }

    if (input.loggedOut) {
        return 'logged out';
    }

    if (input.clientMissing) {
        return 'client missing';
    }

    if (!input.browserConnected) {
        return 'browser disconnected';
    }

    if (!input.pageAlive) {
        return 'page not responding';
    }

    if (!input.isAuthenticated) {
        return input.hasQr ? 'waiting for QR scan' : 'not authenticated';
    }

    if (!input.storeReady) {
        const missing = (input.missing || []).join(', ') || 'send API';
        return `send API not ready (${missing})`;
    }

    if (!input.isReady) {
        return 'send API ready but ready flag not set';
    }

    return null;
}

/**
 * Decide whether a session that looks "connected" is actually usable, and
 * whether Chromium must be torn down. Authentication alone is never enough.
 */
export function decideRecovery(input) {
    const maxInjectRetries = input.maxInjectRetries ?? MAX_INJECT_RETRIES;
    const maxRestarts = input.maxRestarts ?? MAX_WATCHDOG_RESTARTS;
    const restartCooldownMs = input.restartCooldownMs ?? RESTART_COOLDOWN_MS;
    const injectRetries = input.injectRetries ?? 0;
    const restarts = input.restarts ?? 0;
    const msSinceLastRestart = input.msSinceLastRestart;

    if (input.shuttingDown) {
        return { action: 'none', reason: 'shutting down' };
    }

    if (input.loggedOut) {
        return { action: 'none', reason: 'logged out' };
    }

    if (input.bootInProgress) {
        return { action: 'none', reason: 'boot in progress' };
    }

    if (input.clientMissing) {
        return { action: 'restart', reason: 'client missing' };
    }

    if (!input.browserConnected) {
        return { action: 'restart', reason: 'browser disconnected' };
    }

    if (!input.pageAlive) {
        return {
            action: 'restart',
            reason: input.isReady ? 'ready but page is invalid' : 'puppeteer is not responding',
        };
    }

    if (input.hasQr && !input.isAuthenticated) {
        return { action: 'none', reason: 'waiting for QR scan' };
    }

    if (input.isReady && input.storeReady) {
        return { action: 'none', reason: 'session healthy' };
    }

    if (input.isAuthenticated && input.storeReady) {
        return {
            action: 'mark-ready',
            reason: 'send API ready without library ready event',
        };
    }

    if (input.isAuthenticated && !input.storeReady) {
        if (injectRetries < maxInjectRetries) {
            return {
                action: 'reinject',
                reason: 'authenticated but send API not injected',
            };
        }

        if (restarts >= maxRestarts) {
            return {
                action: 'none',
                reason: 'restart limit reached while send API missing',
            };
        }

        if (msSinceLastRestart != null && msSinceLastRestart < restartCooldownMs) {
            return {
                action: 'none',
                reason: 'restart cooldown while send API missing',
            };
        }

        return {
            action: 'restart',
            reason: 'authenticated but send API still missing after injection retries',
        };
    }

    if (!input.isAuthenticated && !input.hasQr) {
        if (restarts >= maxRestarts) {
            return { action: 'none', reason: 'restart limit reached before auth' };
        }

        if (msSinceLastRestart != null && msSinceLastRestart < restartCooldownMs) {
            return { action: 'none', reason: 'restart cooldown before auth' };
        }

        return { action: 'restart', reason: 'not authenticated and no QR' };
    }

    return { action: 'none', reason: 'waiting' };
}

export function shouldReuseAuthenticatedBrowser(input) {
    return Boolean(
        input.isAuthenticated
        && input.pageAlive
        && input.browserConnected
        && !input.loggedOut
        && !input.clientMissing,
    );
}

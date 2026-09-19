import test from 'node:test';
import assert from 'node:assert/strict';
import {
    interpretRuntimeSnapshot,
    probeLogKey,
    collectClientInfoPayload,
    createChangeLogger,
    readinessBlocker,
    decideRecovery,
    shouldReuseAuthenticatedBrowser,
    inspectWhatsAppRuntime,
    needsWWebJSInjection,
    MAX_INJECT_RETRIES,
    MAX_WATCHDOG_RESTARTS,
    RESTART_COOLDOWN_MS,
} from './sessionHealth.js';

function snapshot(overrides = {}) {
    return {
        documentReadyState: 'complete',
        hasRequire: true,
        hasDebugVersion: true,
        webVersion: '2.3000.1020000000',
        authState: 'CONNECTED',
        hasSynced: true,
        hasStore: true,
        hasWWebJS: true,
        hasGetChat: true,
        hasSendMessage: true,
        hasStoreChat: true,
        hasWidFactory: true,
        hasFindOrCreateChat: true,
        hasAppState: true,
        ...overrides,
    };
}

test('inspectWhatsAppRuntime reports missing send API on an empty window', () => {
    const previous = {
        Store: globalThis.Store,
        WWebJS: globalThis.WWebJS,
        AuthStore: globalThis.AuthStore,
        Debug: globalThis.Debug,
        require: globalThis.require,
    };

    delete globalThis.Store;
    delete globalThis.WWebJS;
    delete globalThis.AuthStore;
    delete globalThis.Debug;
    delete globalThis.require;

    try {
        const result = inspectWhatsAppRuntime();
        assert.equal(result.hasStore, false);
        assert.equal(result.hasWWebJS, false);
        assert.equal(result.hasGetChat, false);
        assert.equal(result.hasSendMessage, false);
    } finally {
        for (const [key, value] of Object.entries(previous)) {
            if (value === undefined) {
                delete globalThis[key];
            } else {
                globalThis[key] = value;
            }
        }
    }
});

test('interpretRuntimeSnapshot requires getChat and sendMessage, not only Store', () => {
    const partial = interpretRuntimeSnapshot(snapshot({
        hasGetChat: false,
        hasSendMessage: false,
    }));

    assert.equal(partial.ok, false);
    assert.deepEqual(partial.missing, [
        'window.WWebJS.getChat',
        'window.WWebJS.sendMessage',
    ]);

    const complete = interpretRuntimeSnapshot(snapshot());
    assert.equal(complete.ok, true);
    assert.deepEqual(complete.missing, []);
});

test('library-style Store+WWebJS check is not enough for send readiness', () => {
    const libraryReady = interpretRuntimeSnapshot(snapshot({
        hasGetChat: false,
        hasSendMessage: false,
        hasStoreChat: false,
        hasWidFactory: false,
    }));

    assert.equal(libraryReady.snapshot.hasStore, true);
    assert.equal(libraryReady.snapshot.hasWWebJS, true);
    assert.equal(libraryReady.ok, false);
    assert.ok(libraryReady.missing.includes('window.WWebJS.getChat'));
});

test('createChangeLogger only emits when the probe signature changes', () => {
    const messages = [];
    const log = createChangeLogger((message) => messages.push(message));

    assert.equal(log('missing:window.Store', 'Store missing'), true);
    assert.equal(log('missing:window.Store', 'Store missing again'), false);
    assert.equal(log('ok', 'send API ready'), true);
    assert.deepEqual(messages, ['Store missing', 'send API ready']);
});

test('probeLogKey is stable for the same missing set', () => {
    const probe = interpretRuntimeSnapshot(snapshot({ hasGetChat: false }));
    assert.equal(probeLogKey(probe), 'missing:window.WWebJS.getChat');
    assert.equal(probeLogKey({ ok: true, missing: [] }), 'ok');
    assert.equal(probeLogKey({ ok: false, error: 'store probe timed out' }), 'error:store probe timed out');
});

test('readinessBlocker distinguishes auth from missing send API', () => {
    assert.equal(readinessBlocker({
        isAuthenticated: true,
        pageAlive: true,
        browserConnected: true,
        storeReady: false,
        missing: ['window.WWebJS.getChat'],
        isReady: false,
    }), 'send API not ready (window.WWebJS.getChat)');

    assert.equal(readinessBlocker({
        isAuthenticated: true,
        pageAlive: true,
        browserConnected: true,
        storeReady: true,
        isReady: false,
    }), 'send API ready but ready flag not set');

    assert.equal(readinessBlocker({
        isAuthenticated: false,
        hasQr: true,
        pageAlive: true,
        browserConnected: true,
        storeReady: false,
        isReady: false,
    }), 'waiting for QR scan');
});

test('watchdog marks ready when send API is present even without the library event', () => {
    const decision = decideRecovery({
        isAuthenticated: true,
        isReady: false,
        storeReady: true,
        pageAlive: true,
        browserConnected: true,
    });

    assert.equal(decision.action, 'mark-ready');
    assert.match(decision.reason, /send API ready/);
});

test('collectClientInfoPayload falls back when getMaybeMePnUser is missing', () => {
    const previous = {
        Store: globalThis.Store,
    };

    globalThis.Store = {
        Conn: {
            serialize() {
                return { pushname: 'QNF', platform: 'web' };
            },
            wid: { _serialized: '555199999999@c.us' },
        },
        User: {},
    };

    try {
        const info = collectClientInfoPayload();
        assert.equal(info.pushname, 'QNF');
        assert.equal(info.wid._serialized, '555199999999@c.us');
    } finally {
        if (previous.Store === undefined) {
            delete globalThis.Store;
        } else {
            globalThis.Store = previous.Store;
        }
    }
});

test('watchdog injects LoadUtils when Store exists without WWebJS', () => {
    const snap = snapshot({
        hasWWebJS: false,
        hasGetChat: false,
        hasSendMessage: false,
        hasSynced: true,
        hasGetMaybeMePnUser: false,
    });

    assert.equal(needsWWebJSInjection(snap), true);
    assert.equal(needsWWebJSInjection(snapshot()), false);

    const decision = decideRecovery({
        isAuthenticated: true,
        isReady: false,
        storeReady: false,
        pageAlive: true,
        browserConnected: true,
        injectRetries: 0,
        needsWWebJSInjection: true,
        missing: ['window.WWebJS', 'window.WWebJS.getChat', 'window.WWebJS.sendMessage'],
    });

    assert.equal(decision.action, 'inject-utils');
    assert.match(decision.reason, /WWebJS missing/);
});

test('watchdog retries injection before destroying an authenticated live page', () => {
    const first = decideRecovery({
        isAuthenticated: true,
        isReady: false,
        storeReady: false,
        pageAlive: true,
        browserConnected: true,
        injectRetries: 0,
        missing: ['window.WWebJS'],
    });

    assert.equal(first.action, 'reinject');

    const afterRetries = decideRecovery({
        isAuthenticated: true,
        isReady: false,
        storeReady: false,
        pageAlive: true,
        browserConnected: true,
        injectRetries: MAX_INJECT_RETRIES,
        restarts: 0,
        msSinceLastRestart: RESTART_COOLDOWN_MS,
        missing: ['window.WWebJS'],
    });

    assert.equal(afterRetries.action, 'restart');
    assert.match(afterRetries.reason, /send API still missing/);
});

test('watchdog does not restart-loop a live authenticated session', () => {
    const cooldown = decideRecovery({
        isAuthenticated: true,
        isReady: false,
        storeReady: false,
        pageAlive: true,
        browserConnected: true,
        injectRetries: MAX_INJECT_RETRIES,
        restarts: 1,
        msSinceLastRestart: 1000,
    });

    assert.equal(cooldown.action, 'none');
    assert.match(cooldown.reason, /cooldown/);

    const limited = decideRecovery({
        isAuthenticated: true,
        isReady: false,
        storeReady: false,
        pageAlive: true,
        browserConnected: true,
        injectRetries: MAX_INJECT_RETRIES,
        restarts: MAX_WATCHDOG_RESTARTS,
        msSinceLastRestart: RESTART_COOLDOWN_MS,
    });

    assert.equal(limited.action, 'none');
    assert.match(limited.reason, /limit reached/);
});

test('dead page or missing client still restarts', () => {
    assert.equal(decideRecovery({
        clientMissing: true,
        pageAlive: false,
        browserConnected: false,
    }).action, 'restart');

    assert.equal(decideRecovery({
        isReady: true,
        pageAlive: false,
        browserConnected: true,
        isAuthenticated: true,
    }).action, 'restart');
});

test('boot keeps a live authenticated Chromium instead of destroying it', () => {
    assert.equal(shouldReuseAuthenticatedBrowser({
        isAuthenticated: true,
        pageAlive: true,
        browserConnected: true,
        loggedOut: false,
        clientMissing: false,
    }), true);

    assert.equal(shouldReuseAuthenticatedBrowser({
        isAuthenticated: true,
        pageAlive: false,
        browserConnected: true,
    }), false);
});

test('QR wait and in-progress boot are not treated as failures', () => {
    assert.equal(decideRecovery({
        hasQr: true,
        isAuthenticated: false,
        pageAlive: true,
        browserConnected: true,
        isReady: false,
        storeReady: false,
    }).action, 'none');

    assert.equal(decideRecovery({
        bootInProgress: true,
        clientMissing: true,
    }).action, 'none');
});

import './loadEnv.js';
import { execFile } from 'child_process';
import { randomUUID } from 'crypto';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { promisify } from 'util';
import pkg from 'whatsapp-web.js';
import QRCode from 'qrcode';
import qrcode from 'qrcode-terminal';
import express from 'express';
import { createRequire } from 'module';
import {
    buildCommandPayload,
    createCommandForwarder,
    isSupportedCommand,
} from './commands.js';
import {
    MAX_INJECT_RETRIES,
    MAX_WATCHDOG_RESTARTS,
    RESTART_COOLDOWN_MS,
    collectClientInfoPayload,
    createChangeLogger,
    decideRecovery,
    inspectWhatsAppRuntime,
    interpretRuntimeSnapshot,
    needsWWebJSInjection,
    probeLogKey,
    readinessBlocker,
    shouldReuseAuthenticatedBrowser,
} from './sessionHealth.js';

const execFileAsync = promisify(execFile);
const FFMPEG_BIN = process.env.FFMPEG_PATH || 'ffmpeg';
const nodeRequire = createRequire(import.meta.url);
const { LoadUtils } = nodeRequire('whatsapp-web.js/src/util/Injected/Utils.js');
const ClientInfo = nodeRequire('whatsapp-web.js/src/structures/ClientInfo.js');

const { Client, LocalAuth, MessageMedia } = pkg;

const PORT = process.env.WHATSAPP_PORT || 3001;
const MAX_SEND_ATTEMPTS = 3;
const MAX_INIT_ATTEMPTS = 3;
const RETRY_DELAY_MS = 1500;
const READY_WAIT_MS = 90000;
const DESTROY_TIMEOUT_MS = 8000;
const BROWSER_CLOSE_TIMEOUT_MS = 3000;
const PROFILE_RELEASE_WAIT_MS = 1000;
const INITIALIZE_TIMEOUT_MS = 90000;
const PROBE_TIMEOUT_MS = 8000;
const INJECT_RETRY_WAIT_MS = 45000;
const INJECT_REPLAY_TIMEOUT_MS = 35000;
const WATCHDOG_INTERVAL_MS = 45000;
const UNHEALTHY_WA_STATES = new Set([
    'TIMEOUT',
    'UNLAUNCHED',
    'CONFLICT',
    'UNPAIRED',
    'UNPAIRED_IDLE',
]);
const GROUP_ID = process.env.WHATSAPP_GROUP_ID || '';
const LARAVEL_WEBHOOK_URL = process.env.WHATSAPP_LARAVEL_WEBHOOK_URL || '';
const WEBHOOK_SECRET = process.env.WHATSAPP_WEBHOOK_SECRET || '';

let client = null;
let clientGeneration = 0;
let isReady = false;
let isAuthenticated = false;
let loggedOut = false;
let shuttingDown = false;
let lastQr = null;
let sendQueue = Promise.resolve();
let startPromise = null;
let bootReason = 'startup';
let watchdogTimer = null;
let httpServer = null;
let httpRetryTimer = null;
let libraryReadyEvent = false;
let lastBlocker = null;
let lastRecoveryReason = null;
let lastStoreProbe = null;
let injectRetryCount = 0;
let watchdogRestartCount = 0;
let lastRestartAt = 0;
let injectionReplayInFlight = false;

const logProbeChange = createChangeLogger((message) => console.log(message));
const logBlockerChange = createChangeLogger((message) => console.log(message));
const logRecoveryDecision = createChangeLogger((message) => console.log(message));

const forwardCommand = createCommandForwarder({
    laravelWebhookUrl: LARAVEL_WEBHOOK_URL,
    webhookSecret: WEBHOOK_SECRET,
    groupId: GROUP_ID,
});

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function withTimeout(promise, ms, message) {
    let timer;

    try {
        return await Promise.race([
            promise,
            new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error(message)), ms);
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}

function currentClient() {
    if (!client) {
        throw new Error('WhatsApp client is not available');
    }

    return client;
}

function generationOf(instance) {
    return instance?.generation || clientGeneration || 0;
}

function isTransientPuppeteerError(err) {
    const name = err?.name || '';
    const message = err?.message || String(err);

    if (/TargetCloseError|ProtocolError/i.test(name)) {
        return true;
    }

    return /detached Frame|Execution context was destroyed|Session closed|Target closed|Target page, context or browser has been closed|Protocol error|Navigation failed because browser has disconnected|Most likely the page has been closed|browser has disconnected/i.test(message);
}

function isRecoverableSendError(err) {
    const message = err?.message || String(err);

    if (/WhatsApp client is not available|client\.initialize\(\) timed out|page probe failed|send API not ready|WhatsApp client not ready/i.test(message)) {
        return true;
    }

    if (/Cannot read properties of undefined \(reading 'getChat'\)|Cannot read property 'getChat' of undefined/i.test(message)) {
        return true;
    }

    return isTransientPuppeteerError(err);
}

function diagnosticState(extra = {}) {
    return {
        shuttingDown,
        loggedOut,
        bootInProgress: Boolean(startPromise),
        clientMissing: !client,
        browserConnected: isBrowserConnected(client),
        pageAlive: extra.pageAlive,
        hasQr: Boolean(lastQr),
        isReady,
        isAuthenticated,
        storeReady: Boolean(extra.storeReady ?? lastStoreProbe?.ok),
        missing: extra.missing ?? lastStoreProbe?.missing ?? [],
        needsWWebJSInjection: needsWWebJSInjection(extra.snapshot ?? lastStoreProbe?.snapshot),
        injectRetries: injectRetryCount,
        restarts: watchdogRestartCount,
        msSinceLastRestart: lastRestartAt ? Date.now() - lastRestartAt : null,
        maxInjectRetries: MAX_INJECT_RETRIES,
        maxRestarts: MAX_WATCHDOG_RESTARTS,
        restartCooldownMs: RESTART_COOLDOWN_MS,
    };
}

function noteBlocker(reason) {
    lastBlocker = reason || null;

    if (!reason || isReady) {
        return;
    }

    logBlockerChange(
        `blocker:${reason}`,
        `WhatsApp not ready generation=${generationOf(client)}: ${reason}`,
    );
}

function isBrowserConnected(instance = client) {
    try {
        const browser = instance?.pupBrowser;

        if (!browser) {
            return false;
        }

        if (typeof browser.connected === 'boolean') {
            return browser.connected;
        }

        if (typeof browser.isConnected === 'function') {
            return browser.isConnected();
        }

        return true;
    } catch {
        return false;
    }
}

function isCurrentInstance(instance) {
    return Boolean(instance) && client === instance;
}

async function probeWhatsAppStore() {
    const page = client?.pupPage;

    if (!page) {
        const probe = interpretRuntimeSnapshot(null, 'page missing');
        lastStoreProbe = probe;
        logProbeChange(
            probeLogKey(probe),
            `WhatsApp send API probe generation=${generationOf(client)}: page missing`,
        );
        return probe;
    }

    try {
        if (page.isClosed()) {
            const probe = interpretRuntimeSnapshot(null, 'page closed');
            lastStoreProbe = probe;
            logProbeChange(
                probeLogKey(probe),
                `WhatsApp send API probe generation=${generationOf(client)}: page closed`,
            );
            return probe;
        }
    } catch (err) {
        const probe = interpretRuntimeSnapshot(null, err.message);
        lastStoreProbe = probe;
        return probe;
    }

    try {
        const snapshot = await withTimeout(
            page.evaluate(inspectWhatsAppRuntime),
            PROBE_TIMEOUT_MS,
            'store probe timed out',
        );
        const probe = interpretRuntimeSnapshot(snapshot);
        lastStoreProbe = probe;

        const details = probe.ok
            ? `ok webVersion=${snapshot.webVersion || 'unknown'} authState=${snapshot.authState || 'unknown'} hasSynced=${Boolean(snapshot.hasSynced)}`
            : `missing=${probe.missing.join(', ') || 'unknown'} webVersion=${snapshot.webVersion || 'unknown'} authState=${snapshot.authState || 'unknown'} hasSynced=${Boolean(snapshot.hasSynced)} document=${snapshot.documentReadyState || 'unknown'} require=${Boolean(snapshot.hasRequire)} clientInfoPn=${Boolean(snapshot.hasGetMaybeMePnUser)} clientInfoLid=${Boolean(snapshot.hasGetMaybeMeLidUser)} serialize=${Boolean(snapshot.hasConnSerialize)}`;

        logProbeChange(
            probeLogKey(probe),
            `WhatsApp send API probe generation=${generationOf(client)}: ${details}`,
        );

        return probe;
    } catch (err) {
        const probe = interpretRuntimeSnapshot(null, err.message);
        lastStoreProbe = probe;
        logProbeChange(
            probeLogKey(probe),
            `WhatsApp send API probe generation=${generationOf(client)} failed: ${err.message}`,
        );
        return probe;
    }
}

async function markReadyIfSessionHealthy(reason, instance = client) {
    if (shuttingDown || loggedOut || !instance || !isCurrentInstance(instance)) {
        return false;
    }

    if (!isAuthenticated) {
        noteBlocker('not authenticated');
        return false;
    }

    const pageAlive = await probePage();

    if (!isCurrentInstance(instance)) {
        return false;
    }

    if (!pageAlive) {
        noteBlocker('page not responding');
        return false;
    }

    let store = await probeWhatsAppStore();

    if (!isCurrentInstance(instance)) {
        return false;
    }

    if (
        !store.ok
        && needsWWebJSInjection(store.snapshot)
        && injectRetryCount < MAX_INJECT_RETRIES
        && !injectionReplayInFlight
    ) {
        await injectWWebJS(instance, 'Store present without WWebJS');

        if (!isCurrentInstance(instance)) {
            return false;
        }

        store = await probeWhatsAppStore();

        if (!isCurrentInstance(instance)) {
            return false;
        }
    }

    if (!store.ok) {
        noteBlocker(`send API not ready (${store.missing.join(', ') || store.error || 'unknown'})`);
        return false;
    }

    if (!isReady) {
        isReady = true;
        lastQr = null;
        lastBlocker = null;
        injectRetryCount = 0;
        watchdogRestartCount = 0;
        console.log(
            `WhatsApp client generation=${generationOf(instance)} ready via ${reason}`,
        );
    }

    return true;
}

async function injectWWebJS(instance, reason) {
    if (!isCurrentInstance(instance) || injectionReplayInFlight) {
        return false;
    }

    const page = instance.pupPage;
    const generation = generationOf(instance);

    if (!page) {
        return false;
    }

    try {
        if (page.isClosed()) {
            return false;
        }
    } catch {
        return false;
    }

    injectionReplayInFlight = true;
    injectRetryCount += 1;
    lastRecoveryReason = reason;
    console.log(
        `WhatsApp injecting LoadUtils generation=${generation} attempt=${injectRetryCount}/${MAX_INJECT_RETRIES}: ${reason}`,
    );

    try {
        await withTimeout(
            page.evaluate(LoadUtils),
            PROBE_TIMEOUT_MS,
            'LoadUtils injection timed out',
        );

        if (!isCurrentInstance(instance)) {
            return false;
        }

        if (!instance.info) {
            try {
                const infoData = await withTimeout(
                    page.evaluate(collectClientInfoPayload),
                    PROBE_TIMEOUT_MS,
                    'client info probe timed out',
                );
                instance.info = new ClientInfo(instance, infoData);
            } catch (err) {
                console.error(
                    `WhatsApp ClientInfo fallback failed generation=${generation}:`,
                    err.message,
                );
            }
        }

        if (!instance._qnfListenersAttached && typeof instance.attachEventListeners === 'function') {
            try {
                await instance.attachEventListeners();
                instance._qnfListenersAttached = true;
                console.log(`WhatsApp message listeners attached generation=${generation}`);
            } catch (err) {
                console.error(
                    `WhatsApp attachEventListeners failed generation=${generation}:`,
                    err.message,
                );
            }
        }

        return isCurrentInstance(instance);
    } catch (err) {
        console.error(
            `WhatsApp LoadUtils injection failed generation=${generation}:`,
            err.message,
        );
        return false;
    } finally {
        injectionReplayInFlight = false;
    }
}

async function retryLibraryInjection(instance, reason) {
    if (!isCurrentInstance(instance) || injectionReplayInFlight) {
        return false;
    }

    const probe = lastStoreProbe?.snapshot
        ? lastStoreProbe
        : await probeWhatsAppStore();

    if (!isCurrentInstance(instance)) {
        return false;
    }

    if (needsWWebJSInjection(probe?.snapshot)) {
        return injectWWebJS(instance, reason);
    }

    const page = instance.pupPage;
    const generation = generationOf(instance);

    if (!page) {
        return false;
    }

    try {
        if (page.isClosed()) {
            return false;
        }
    } catch {
        return false;
    }

    injectionReplayInFlight = true;
    injectRetryCount += 1;
    lastRecoveryReason = reason;
    console.log(
        `WhatsApp retrying Store injection generation=${generation} attempt=${injectRetryCount}/${MAX_INJECT_RETRIES}: ${reason}`,
    );

    try {
        const replay = await withTimeout(
            page.evaluate(async () => {
                if (typeof window.onAppStateHasSyncedEvent === 'function') {
                    await window.onAppStateHasSyncedEvent();
                    return 'hasSynced';
                }

                return 'missing-hasSynced-hook';
            }),
            INJECT_REPLAY_TIMEOUT_MS,
            'injection replay timed out',
        );

        if (!isCurrentInstance(instance)) {
            return false;
        }

        if (replay === 'missing-hasSynced-hook' && typeof instance.inject === 'function') {
            console.log(`WhatsApp hasSynced hook missing generation=${generation}; calling client.inject()`);
            await withTimeout(
                instance.inject(),
                INITIALIZE_TIMEOUT_MS,
                'client.inject() timed out',
            );
        }

        return isCurrentInstance(instance);
    } catch (err) {
        if (!isCurrentInstance(instance)) {
            return false;
        }

        console.error(
            `WhatsApp Store injection retry failed generation=${generation}:`,
            err.message,
        );

        try {
            if (typeof instance.inject === 'function') {
                await withTimeout(
                    instance.inject(),
                    INITIALIZE_TIMEOUT_MS,
                    'client.inject() timed out',
                );
            }
        } catch (injectErr) {
            console.error(
                `WhatsApp client.inject() retry failed generation=${generation}:`,
                injectErr.message,
            );
            return false;
        }

        return isCurrentInstance(instance);
    } finally {
        injectionReplayInFlight = false;
    }
}

function resolveChromePath() {
    if (process.env.PUPPETEER_EXECUTABLE_PATH) {
        return process.env.PUPPETEER_EXECUTABLE_PATH;
    }

    const candidates = process.platform === 'win32'
        ? [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            path.join(process.env.LOCALAPPDATA || '', 'Google\\Chrome\\Application\\chrome.exe'),
        ]
        : [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

    return candidates.find((candidate) => fs.existsSync(candidate));
}

function createWhatsAppClient() {
    clientGeneration += 1;
    const generation = clientGeneration;
    const chromePath = resolveChromePath();

    const instance = new Client({
        // Snap Chromium cannot lock a profile under /var/www; keep the session in $HOME.
        authStrategy: new LocalAuth({
            dataPath: process.env.WWEBJS_AUTH_PATH || './.wwebjs_auth',
        }),
        // A stale local WhatsApp Web HTML cache can authenticate but never
        // inject window.WWebJS, so sends fail with "getChat of undefined".
        webVersionCache: {
            type: 'none',
        },
        puppeteer: {
            headless: true,
            protocolTimeout: 120000,
            executablePath: chromePath || undefined,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                // Keep WhatsApp Web in one renderer so Chrome does not discard idle iframes.
                '--disable-features=IsolateOrigins,site-per-process,Translate,BackForwardCache,MediaRouter,MemorySaverMode',
                '--disable-site-isolation-trials',
                '--renderer-process-limit=1',
                '--memory-pressure-off',
            ],
        },
    });

    instance.generation = generation;

    return instance;
}

function attachClientEvents(instance) {
    const generation = generationOf(instance);

    instance.on('qr', (qr) => {
        if (!isCurrentInstance(instance)) {
            return;
        }

        lastQr = qr;
        console.log(`Scan the QR code below to authenticate: generation=${generation}`);
        qrcode.generate(qr, { small: true });
        console.log(`Or open http://127.0.0.1:${PORT}/qr in your browser.`);
    });

    instance.on('authenticated', () => {
        if (!isCurrentInstance(instance)) {
            return;
        }

        isAuthenticated = true;
        loggedOut = false;
        lastQr = null;
        console.log(`WhatsApp authenticated successfully generation=${generation}`);
        markReadyIfSessionHealthy('authenticated event', instance).catch((err) => {
            console.error(
                `Authenticated health check failed generation=${generation}:`,
                err.message,
            );
        });
    });

    instance.on('auth_failure', (msg) => {
        if (!isCurrentInstance(instance)) {
            return;
        }

        isReady = false;
        isAuthenticated = false;
        console.error(`Authentication failed generation=${generation}:`, msg);
    });

    instance.on('ready', () => {
        if (!isCurrentInstance(instance)) {
            return;
        }

        libraryReadyEvent = true;
        isAuthenticated = true;
        loggedOut = false;
        lastQr = null;
        console.log(`WhatsApp library ready event generation=${generation}`);
        markReadyIfSessionHealthy('library ready event', instance).catch((err) => {
            console.error(
                `Ready event health check failed generation=${generation}:`,
                err.message,
            );
        });
    });

    instance.on('change_state', (state) => {
        if (!isCurrentInstance(instance)) {
            return;
        }

        console.log(`WhatsApp state changed generation=${generation}: ${state}`);

        if (String(state).toUpperCase() !== 'TIMEOUT') {
            return;
        }

        isReady = false;
        recoverClient(`change_state: ${state}`).catch((err) => {
            console.error('Reconnect after state change failed:', err.message);
        });
    });

    instance.on('disconnected', (reason) => {
        if (!isCurrentInstance(instance)) {
            return;
        }

        isReady = false;
        console.log(`Client disconnected generation=${generation}:`, reason);

        if (String(reason).toUpperCase() === 'LOGOUT') {
            loggedOut = true;
            isAuthenticated = false;
            lastQr = null;
            console.log('WhatsApp logged out; waiting for a new QR scan.');
            return;
        }

        setTimeout(() => {
            if (shuttingDown || loggedOut) {
                return;
            }

            if (client !== instance && client !== null) {
                return;
            }

            if (isReady && !startPromise) {
                return;
            }

            recoverClient(`disconnected: ${reason}`).catch((err) => {
                console.error('Reconnect after disconnect failed:', err.message);
            });
        }, 2000);
    });

    instance.on('message_create', async (msg) => {
        try {
            if (!isCurrentInstance(instance)) {
                return;
            }

            if (msg.isStatus || !isSupportedCommand(msg.body)) {
                return;
            }

            const chatId = msg.fromMe ? msg.to : msg.from;
            if (GROUP_ID && chatId !== GROUP_ID) {
                console.log(`Ignoring command from other chat: ${chatId}`);
                return;
            }

            console.log(`Command received: ${String(msg.body).trim()} chat=${chatId} fromMe=${Boolean(msg.fromMe)}`);

            const payload = await buildCommandPayload(msg, currentClient());
            payload.chat_id = chatId;
            payload.from_me = false;

            const result = await forwardCommand(payload);

            if (result?.reply) {
                await enqueueSend(() =>
                    sendWithRetry(() => currentClient().sendMessage(chatId, result.reply), 'Command reply'),
                );
            }

            if (result?.audio_path) {
                try {
                    await enqueueSend(() =>
                        sendWithRetry(() => sendVoiceNote(chatId, result.audio_path), 'Command voice note'),
                    );
                } finally {
                    if (result.cleanup_audio) {
                        fs.unlink(result.audio_path, () => {});
                    }
                }
            }

            if (!result?.reply && !result?.audio_path) {
                console.log('No reply from Laravel for this command.', result);
            }
        } catch (err) {
            console.error('Command handling failed:', err.message);
        }
    });
}

function startWhatsAppClient() {
    if (shuttingDown) {
        return Promise.reject(new Error('WhatsApp service is shutting down'));
    }

    if (startPromise) {
        return startPromise;
    }

    const pending = bootWhatsAppClient().finally(() => {
        if (startPromise === pending) {
            startPromise = null;
        }
    });

    startPromise = pending;

    return startPromise;
}

function killBrowserProcess(instance) {
    try {
        const proc = instance?.pupBrowser?.process?.();

        if (proc && !proc.killed) {
            proc.kill('SIGKILL');
            return true;
        }
    } catch {
        // Browser already gone.
    }

    return false;
}

async function destroyClient() {
    const instance = client;
    const generation = generationOf(instance);

    client = null;
    isReady = false;
    isAuthenticated = false;
    libraryReadyEvent = false;
    lastStoreProbe = null;
    injectRetryCount = 0;
    injectionReplayInFlight = false;

    if (!instance) {
        return;
    }

    await Promise.race([
        instance.destroy().catch(() => {}),
        sleep(DESTROY_TIMEOUT_MS),
    ]);

    if (isBrowserConnected(instance)) {
        try {
            await Promise.race([
                instance.pupBrowser.close().catch(() => {}),
                sleep(BROWSER_CLOSE_TIMEOUT_MS),
            ]);
        } catch {
            // Browser already gone.
        }
    }

    let killed = false;

    if (isBrowserConnected(instance)) {
        killed = killBrowserProcess(instance);
    }

    try {
        instance.removeAllListeners();
    } catch {
        // Instance already torn down.
    }

    console.log(`WhatsApp old client destroyed generation=${generation}`);

    if (killed) {
        await sleep(PROFILE_RELEASE_WAIT_MS);
    }
}

async function waitForReadyOrQr(timeoutMs = READY_WAIT_MS) {
    const deadline = Date.now() + timeoutMs;
    let lastStoreCheck = 0;

    while (Date.now() < deadline) {
        if (shuttingDown) {
            throw new Error('WhatsApp service is shutting down');
        }

        if (lastQr && !isReady && !isAuthenticated) {
            return 'qr';
        }

        if (isReady && lastStoreProbe?.ok && await probePage()) {
            return 'ready';
        }

        // Avoid hammering page.evaluate() while the library injects Store/WWebJS.
        if (Date.now() - lastStoreCheck >= 2000) {
            lastStoreCheck = Date.now();

            if (await markReadyIfSessionHealthy('send API probe')) {
                return 'ready';
            }
        }

        await sleep(500);
    }

    if (await markReadyIfSessionHealthy('ready wait timeout')) {
        return 'ready';
    }

    if (lastQr && !isAuthenticated) {
        return 'qr';
    }

    if (isAuthenticated && await probePage()) {
        return 'authenticated-pending-store';
    }

    throw new Error(lastBlocker || 'WhatsApp client not ready');
}

async function bootWhatsAppClient() {
    const isRecovery = bootReason !== 'startup';

    for (let attempt = 1; attempt <= MAX_INIT_ATTEMPTS; attempt++) {
        if (shuttingDown) {
            throw new Error('WhatsApp service is shutting down');
        }

        const pageAlive = client ? await probePage() : false;
        const reuseSession = shouldReuseAuthenticatedBrowser({
            isAuthenticated,
            pageAlive,
            browserConnected: isBrowserConnected(client),
            loggedOut,
            clientMissing: !client,
        });

        if (reuseSession) {
            console.log(
                `WhatsApp keeping authenticated Chromium generation=${generationOf(client)} (attempt ${attempt}/${MAX_INIT_ATTEMPTS})`,
            );
        } else {
            isReady = false;
            lastQr = null;
            await destroyClient();

            client = createWhatsAppClient();
            const generation = generationOf(client);
            attachClientEvents(client);

            console.log(
                `WhatsApp new client initializing generation=${generation} (attempt ${attempt}/${MAX_INIT_ATTEMPTS})`,
            );

            try {
                await withTimeout(
                    client.initialize(),
                    INITIALIZE_TIMEOUT_MS,
                    'client.initialize() timed out',
                );
            } catch (err) {
                console.error(
                    `WhatsApp initialize failed (attempt ${attempt}/${MAX_INIT_ATTEMPTS}) generation=${generation}:`,
                    err.message,
                );

                if (attempt === MAX_INIT_ATTEMPTS) {
                    lastRestartAt = Date.now();
                    throw err;
                }

                await sleep(RETRY_DELAY_MS * attempt);
                continue;
            }
        }

        const generation = generationOf(client);

        try {
            let outcome = await waitForReadyOrQr(READY_WAIT_MS);

            if (outcome === 'authenticated-pending-store' && isCurrentInstance(client)) {
                const replayed = await retryLibraryInjection(
                    client,
                    'boot: authenticated but send API not injected',
                );

                if (replayed) {
                    outcome = await waitForReadyOrQr(INJECT_RETRY_WAIT_MS);
                }
            }

            if (outcome === 'qr') {
                console.log(`WhatsApp waiting for QR scan generation=${generation}`);
                return;
            }

            if (outcome === 'ready') {
                if (!(await probePage())) {
                    throw new Error('WhatsApp client initialized but page probe failed');
                }

                if (isRecovery) {
                    console.log(`WhatsApp recovery completed generation=${generation}`);
                }

                return;
            }

            throw new Error(lastBlocker || 'WhatsApp client not ready');
        } catch (err) {
            const pageStillAlive = await probePage();
            const canKeepBrowser = shouldReuseAuthenticatedBrowser({
                isAuthenticated,
                pageAlive: pageStillAlive,
                browserConnected: isBrowserConnected(client),
                loggedOut,
                clientMissing: !client,
            });

            console.error(
                `WhatsApp initialize failed (attempt ${attempt}/${MAX_INIT_ATTEMPTS}) generation=${generation}:`,
                err.message,
            );

            if (canKeepBrowser) {
                console.log(
                    `WhatsApp not destroying healthy Chromium generation=${generation}: authenticated page is alive`,
                );
            }

            if (attempt === MAX_INIT_ATTEMPTS) {
                lastRestartAt = Date.now();
                throw err;
            }

            if (canKeepBrowser) {
                await sleep(RETRY_DELAY_MS * attempt);
                continue;
            }

            await sleep(RETRY_DELAY_MS * attempt);
        }
    }
}

async function recoverClient(reason, { countRestart = false } = {}) {
    if (shuttingDown || loggedOut) {
        return startPromise || Promise.resolve();
    }

    if (startPromise) {
        return startPromise;
    }

    lastRecoveryReason = reason || 'recovery';
    isReady = false;
    bootReason = lastRecoveryReason;
    lastRestartAt = Date.now();

    if (countRestart) {
        watchdogRestartCount += 1;
    } else {
        watchdogRestartCount = 0;
        injectRetryCount = 0;
    }

    console.error(`Recovering WhatsApp client: ${bootReason}`);
    console.log(`WhatsApp recovery started: ${bootReason}`);

    try {
        await startWhatsAppClient();
    } catch (err) {
        console.error('WhatsApp recovery failed:', err.message);
        throw err;
    }
}

async function probePage() {
    const instance = client;

    if (!instance) {
        return false;
    }

    const page = instance.pupPage;

    if (!page) {
        return false;
    }

    try {
        if (page.isClosed()) {
            return false;
        }
    } catch {
        return false;
    }

    if (!isBrowserConnected(instance)) {
        return false;
    }

    try {
        const readyState = await withTimeout(
            page.evaluate(() => document.readyState),
            PROBE_TIMEOUT_MS,
            'probe evaluate timed out',
        );

        if (!readyState) {
            return false;
        }
    } catch {
        return false;
    }

    if (!isReady || typeof instance.getState !== 'function') {
        return true;
    }

    try {
        const state = await withTimeout(
            instance.getState(),
            PROBE_TIMEOUT_MS,
            'getState timed out',
        );

        if (!state) {
            return true;
        }

        const normalized = String(state).toUpperCase();

        if (UNHEALTHY_WA_STATES.has(normalized)) {
            return false;
        }
    } catch (err) {
        if (isTransientPuppeteerError(err) || /timed out/i.test(err?.message || '')) {
            return false;
        }
    }

    return true;
}

async function waitUntilReady(timeoutMs = READY_WAIT_MS) {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        if (shuttingDown) {
            throw new Error('WhatsApp service is shutting down');
        }

        if (loggedOut) {
            throw new Error('WhatsApp client logged out');
        }

        if (startPromise) {
            await startPromise.catch(() => {});
        }

        if (isReady && await probePage()) {
            const store = await probeWhatsAppStore();

            if (store.ok) {
                return;
            }

            isReady = false;
            noteBlocker(`send API not ready (${store.missing.join(', ') || store.error || 'unknown'})`);
        }

        if (await markReadyIfSessionHealthy('send wait')) {
            return;
        }

        await sleep(500);
    }

    if (await markReadyIfSessionHealthy('send wait timeout fallback')) {
        return;
    }

    throw new Error(lastBlocker || 'WhatsApp client not ready');
}

function buildStatusDiagnostics(pageAlive, extra = {}) {
    const snapshot = lastStoreProbe?.snapshot || null;
    const missing = lastStoreProbe?.missing || [];
    const blocker = readinessBlocker(diagnosticState({
        pageAlive,
        storeReady: Boolean(lastStoreProbe?.ok),
        missing,
    }));

    if (blocker) {
        noteBlocker(blocker);
    }

    return {
        generation: generationOf(client),
        storeReady: Boolean(lastStoreProbe?.ok),
        missing,
        lastBlocker: blocker || lastBlocker,
        lastRecoveryReason,
        libraryReadyEvent,
        webVersion: snapshot?.webVersion || null,
        authState: snapshot?.authState || null,
        hasSynced: Boolean(snapshot?.hasSynced),
        documentReadyState: snapshot?.documentReadyState || extra.documentReadyState || null,
        hasRequire: Boolean(snapshot?.hasRequire),
        hasConnSerialize: Boolean(snapshot?.hasConnSerialize),
        hasGetMaybeMePnUser: Boolean(snapshot?.hasGetMaybeMePnUser),
        hasGetMaybeMeLidUser: Boolean(snapshot?.hasGetMaybeMeLidUser),
        injectRetries: injectRetryCount,
        ...extra,
    };
}

async function getHealthSnapshot() {
    const starting = Boolean(startPromise) && bootReason === 'startup';
    const recovering = Boolean(startPromise) && bootReason !== 'startup';
    const browserConnected = isBrowserConnected(client);

    if (shuttingDown) {
        return {
            ready: false,
            authenticated: false,
            pageAlive: false,
            browserConnected,
            recovering,
            starting,
            hasQr: Boolean(lastQr),
            ...buildStatusDiagnostics(false),
        };
    }

    const pageAlive = client ? await probePage() : false;

    if (pageAlive) {
        await probeWhatsAppStore();
    }

    const storeReady = Boolean(lastStoreProbe?.ok && !loggedOut);
    const ready = Boolean(isReady && pageAlive && browserConnected && storeReady);

    return {
        ready,
        authenticated: Boolean(isAuthenticated && !loggedOut),
        pageAlive,
        browserConnected,
        recovering,
        starting,
        hasQr: Boolean(lastQr),
        ...buildStatusDiagnostics(pageAlive),
    };
}

/**
 * Serialize all sends through one queue — concurrent Puppeteer evaluate()
 * calls are a common trigger for detached Frame errors.
 */
function enqueueSend(task) {
    const run = sendQueue.then(task, task);
    sendQueue = run.catch(() => {});
    return run;
}

async function sendWithRetry(operation, label) {
    let lastError;

    for (let attempt = 1; attempt <= MAX_SEND_ATTEMPTS; attempt++) {
        if (shuttingDown) {
            throw new Error('WhatsApp service is shutting down');
        }

        if (loggedOut) {
            throw new Error('WhatsApp client logged out');
        }

        try {
            if (startPromise) {
                await startPromise;
            }

            await markReadyIfSessionHealthy(`${label}: existing session`);

            if (!(await probePage()) || !isBrowserConnected(client)) {
                await recoverClient(`${label}: page/browser unhealthy`);
            }

            await waitUntilReady();

            const store = await probeWhatsAppStore();

            if (!store.ok) {
                throw new Error(
                    `send API not ready (${store.missing.join(', ') || store.error || 'unknown'})`,
                );
            }

            if (attempt > 1) {
                console.log('WhatsApp send retrying after client recovery');
            }

            return await operation();
        } catch (err) {
            lastError = err;
            console.error(
                `${label} failed (attempt ${attempt}/${MAX_SEND_ATTEMPTS}):`,
                err.message,
            );

            if (loggedOut || shuttingDown) {
                break;
            }

            if (!isRecoverableSendError(err) || attempt === MAX_SEND_ATTEMPTS) {
                break;
            }

            if (
                /send API not ready|getChat/i.test(err.message)
                && client
                && await probePage()
            ) {
                const replayed = await retryLibraryInjection(
                    client,
                    `${label}: send API missing`,
                );

                if (replayed) {
                    isReady = false;
                    continue;
                }
            }

            isReady = false;
            await recoverClient(err.message);
        }
    }

    throw lastError;
}

function startWatchdog() {
    if (watchdogTimer) {
        return;
    }

    watchdogTimer = setInterval(() => {
        if (shuttingDown || loggedOut || startPromise) {
            return;
        }

        enqueueSend(async () => {
            if (shuttingDown || loggedOut || startPromise) {
                return;
            }

            const pageAlive = client ? await probePage() : false;

            if (pageAlive) {
                await probeWhatsAppStore();
            }

            if (await markReadyIfSessionHealthy('watchdog')) {
                return;
            }

            const decision = decideRecovery(diagnosticState({
                pageAlive,
                storeReady: Boolean(lastStoreProbe?.ok),
                missing: lastStoreProbe?.missing || [],
            }));

            logRecoveryDecision(
                `${decision.action}:${decision.reason}`,
                `WhatsApp watchdog generation=${generationOf(client)} action=${decision.action}: ${decision.reason}`,
            );

            if (decision.action === 'none') {
                return;
            }

            if (decision.action === 'mark-ready') {
                await markReadyIfSessionHealthy('watchdog: send API ready');
                return;
            }

            if (decision.action === 'inject-utils' || decision.action === 'reinject') {
                if (client) {
                    await retryLibraryInjection(client, `watchdog: ${decision.reason}`);
                    await markReadyIfSessionHealthy('watchdog after injection retry');
                }
                return;
            }

            if (decision.action === 'restart') {
                await recoverClient(`watchdog: ${decision.reason}`, {
                    countRestart: Boolean(isAuthenticated && pageAlive),
                });
            }
        }).catch((err) => {
            console.error('Watchdog recovery failed:', err.message);
        });
    }, WATCHDOG_INTERVAL_MS);

    watchdogTimer.unref?.();
}

function stopWatchdog() {
    if (!watchdogTimer) {
        return;
    }

    clearInterval(watchdogTimer);
    watchdogTimer = null;
}

startWhatsAppClient().catch((err) => {
    console.error('Initial WhatsApp start failed:', err.message);
});
startWatchdog();

function materializeAudioInput(audioPath, audioBase64, audioFilename) {
    if (typeof audioBase64 === 'string' && audioBase64.length > 0) {
        const ext = path.extname(String(audioFilename || '')) || '.mp3';
        const inputPath = path.join(os.tmpdir(), `qnf-in-${randomUUID()}${ext}`);
        fs.writeFileSync(inputPath, Buffer.from(audioBase64, 'base64'));

        return { inputPath, cleanup: true };
    }

    if (!audioPath) {
        throw new Error('Missing "audioPath" or "audioBase64"');
    }

    try {
        fs.accessSync(audioPath, fs.constants.R_OK);
    } catch (err) {
        const code = err?.code ? ` (${err.code})` : '';
        throw new Error(`Audio file not found: ${audioPath}${code}`);
    }

    return { inputPath: audioPath, cleanup: false };
}

async function convertToVoiceNote(inputPath) {
    try {
        fs.accessSync(inputPath, fs.constants.R_OK);
    } catch (err) {
        const code = err?.code ? ` (${err.code})` : '';
        throw new Error(`Audio file not found: ${inputPath}${code}`);
    }

    const outputPath = path.join(os.tmpdir(), `qnf-voice-${randomUUID()}.ogg`);

    try {
        await execFileAsync(FFMPEG_BIN, [
            '-y',
            '-i', inputPath,
            '-c:a', 'libopus',
            '-b:a', '32k',
            '-ac', '1',
            '-ar', '48000',
            '-vn',
            outputPath,
        ], { windowsHide: true, timeout: 60000 });
    } catch (err) {
        const details = err?.stderr?.toString().trim() || err.message;
        throw new Error(`FFmpeg voice conversion failed: ${details}`);
    }

    return outputPath;
}

function loadVoiceMedia(oggPath) {
    const data = fs.readFileSync(oggPath).toString('base64');

    return new MessageMedia('audio/ogg; codecs=opus', data, path.basename(oggPath));
}

async function sendVoiceNote(to, audioPath, audioBase64, audioFilename) {
    const { inputPath, cleanup } = materializeAudioInput(audioPath, audioBase64, audioFilename);

    try {
        const oggPath = await convertToVoiceNote(inputPath);

        try {
            const media = loadVoiceMedia(oggPath);

            return await currentClient().sendMessage(to, media, { sendAudioAsVoice: true });
        } finally {
            fs.unlink(oggPath, () => {});
        }
    } finally {
        if (cleanup) {
            fs.unlink(inputPath, () => {});
        }
    }
}

// Express API
const app = express();
app.use(express.json({ limit: '8mb' }));

app.get('/status', async (_req, res) => {
    res.json(await getHealthSnapshot());
});

app.get('/qr', async (req, res) => {
    if (isReady) {
        return res
            .type('html')
            .send('<p>WhatsApp já autenticado. Pode fechar esta página.</p>');
    }

    if (!lastQr) {
        return res
            .type('html')
            .send('<p>Aguardando QR code... Atualize em alguns segundos.</p>');
    }

    if (req.query.format === 'png') {
        const png = await QRCode.toBuffer(lastQr, { width: 360, margin: 2 });

        return res.type('png').send(png);
    }

    res.type('html').send(`<!doctype html>
<html lang="pt-BR">
  <body style="font-family:sans-serif;text-align:center;padding:40px">
    <h1>Escaneie o QR do WhatsApp</h1>
    <p>WhatsApp → Aparelhos conectados → Conectar um aparelho</p>
    <img src="/qr?format=png" width="360" height="360" alt="QR code do WhatsApp" />
    <p>Esta página atualiza sozinha a cada 15 segundos.</p>
    <script>setTimeout(() => location.reload(), 15000)</script>
  </body>
</html>`);
});

app.post('/send', async (req, res) => {
    const { to, message } = req.body;
    if (!to || !message) {
        return res.status(400).json({ error: 'Missing "to" or "message"' });
    }

    try {
        await enqueueSend(() =>
            sendWithRetry(() => currentClient().sendMessage(to, message), 'Send'),
        );
        console.log(`Message sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send failed:', err.message);
        res.status(500).json({ error: err.message });
    }
});

app.post('/send-image', async (req, res) => {
    const { to, imagePath, caption } = req.body;
    if (!to || !imagePath) {
        return res.status(400).json({ error: 'Missing "to" or "imagePath"' });
    }

    try {
        await enqueueSend(() =>
            sendWithRetry(() => {
                const media = MessageMedia.fromFilePath(imagePath);
                return currentClient().sendMessage(to, media, { caption: caption || '' });
            }, 'Send image'),
        );
        console.log(`Image sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send image failed:', err.message);
        res.status(500).json({ error: err.message });
    }
});

app.post('/send-audio', async (req, res) => {
    const { to, audioPath, audioBase64, audioFilename } = req.body;
    if (!to || (!audioPath && !audioBase64)) {
        return res.status(400).json({ error: 'Missing "to" or audio payload' });
    }

    try {
        await enqueueSend(() =>
            sendWithRetry(
                () => sendVoiceNote(to, audioPath, audioBase64, audioFilename),
                'Send voice note',
            ),
        );
        console.log(`Voice note sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send audio failed:', err.message);
        res.status(500).json({ error: err.message });
    }
});

app.get('/groups', async (_req, res) => {
    const health = await getHealthSnapshot();

    if (!health.ready) {
        return res.status(503).json({ error: 'WhatsApp client not ready' });
    }

    try {
        const chats = await currentClient().getChats();
        const groups = chats
            .filter((c) => c.isGroup)
            .map((c) => ({ id: c.id._serialized, name: c.name }));
        res.json(groups);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

function startHttpServer() {
    if (httpServer || shuttingDown) {
        return;
    }

    const server = app.listen(PORT, '127.0.0.1', () => {
        if (httpRetryTimer) {
            clearTimeout(httpRetryTimer);
            httpRetryTimer = null;
        }

        console.log(`WhatsApp service listening on http://127.0.0.1:${PORT}`);
        console.log(
            `Commands: group=${GROUP_ID || '(not set)'} webhook=${LARAVEL_WEBHOOK_URL || '(not set)'} secret=${WEBHOOK_SECRET ? 'yes' : 'no'}`,
        );
    });

    httpServer = server;

    server.on('error', (err) => {
        if (err.code === 'EADDRINUSE') {
            console.error(`Port ${PORT} in use, retrying in 3s...`);
            httpServer = null;

            if (!httpRetryTimer) {
                httpRetryTimer = setTimeout(() => {
                    httpRetryTimer = null;
                    startHttpServer();
                }, 3000);
            }

            return;
        }

        console.error('HTTP server error:', err);
        process.exit(1);
    });
}

async function shutdown(signal) {
    if (shuttingDown) {
        return;
    }

    shuttingDown = true;
    console.log(`WhatsApp service shutting down (${signal})`);

    stopWatchdog();

    if (httpRetryTimer) {
        clearTimeout(httpRetryTimer);
        httpRetryTimer = null;
    }

    if (httpServer) {
        await new Promise((resolve) => {
            httpServer.close(() => resolve());
            setTimeout(resolve, 3000);
        });
        httpServer = null;
    }

    await destroyClient();

    if (startPromise) {
        await Promise.race([
            startPromise.catch(() => {}),
            sleep(DESTROY_TIMEOUT_MS),
        ]);
    }

    await destroyClient();
    process.exit(0);
}

process.on('SIGTERM', () => {
    shutdown('SIGTERM').catch((err) => {
        console.error('Shutdown failed:', err.message);
        process.exit(1);
    });
});

process.on('SIGINT', () => {
    shutdown('SIGINT').catch((err) => {
        console.error('Shutdown failed:', err.message);
        process.exit(1);
    });
});

startHttpServer();

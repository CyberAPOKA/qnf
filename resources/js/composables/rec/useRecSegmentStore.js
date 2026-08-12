const DB_NAME = 'qnf-rec';
const DB_VERSION = 1;
const IDB_TIMEOUT_MS = 2_500;

function isAppleMobile() {
    if (typeof navigator === 'undefined') return false;
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function withTimeout(promise, label = 'IndexedDB') {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            reject(new Error(`${label} timeout`));
        }, IDB_TIMEOUT_MS);

        Promise.resolve(promise).then(
            (value) => {
                clearTimeout(timer);
                resolve(value);
            },
            (error) => {
                clearTimeout(timer);
                reject(error);
            },
        );
    });
}

function requestAsPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('IndexedDB request failed'));
    });
}

function transactionDone(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error || new Error('IndexedDB transaction failed'));
        transaction.onabort = () => reject(transaction.error || new Error('IndexedDB transaction aborted'));
    });
}

function createStore(database, name, options, indexes = []) {
    if (database.objectStoreNames.contains(name)) return;

    const store = database.createObjectStore(name, options);
    indexes.forEach(([indexName, keyPath, indexOptions]) => {
        store.createIndex(indexName, keyPath, indexOptions);
    });
}

function openDatabase() {
    if (typeof indexedDB === 'undefined') {
        return Promise.reject(new Error('IndexedDB não está disponível.'));
    }

    return withTimeout(new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const database = request.result;

            createStore(database, 'segments', { keyPath: 'uuid' }, [
                ['sessionUuid', 'sessionUuid', { unique: false }],
                ['sessionSequence', ['sessionUuid', 'sequence'], { unique: true }],
                ['sessionEndedAt', ['sessionUuid', 'endedAt'], { unique: false }],
            ]);
            createStore(database, 'uploadJobs', { keyPath: 'id' }, [
                ['status', 'status', { unique: false }],
                ['sessionUuid', 'sessionUuid', { unique: false }],
                ['segmentUuid', 'segmentUuid', { unique: false }],
            ]);
            createStore(database, 'sessionMeta', { keyPath: 'id' });
            createStore(database, 'processedKeys', { keyPath: 'key' }, [
                ['processedAt', 'processedAt', { unique: false }],
            ]);
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('IndexedDB open failed'));
        request.onblocked = () => reject(new Error('Atualização do IndexedDB bloqueada.'));
    }), 'IndexedDB open');
}

function createMemoryBackend() {
    const segments = new Map();
    const jobs = new Map();
    const sessions = new Map();
    const processed = new Map();

    return {
        async putSegment(segment) {
            const value = {
                createdAt: Date.now(),
                ...segment,
                uuid: segment.uuid || crypto.randomUUID(),
            };
            segments.set(value.uuid, value);
            return value;
        },
        async getSegments(sessionUuid = null) {
            const values = [...segments.values()]
                .filter((item) => !sessionUuid || item.sessionUuid === sessionUuid)
                .sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0));
            return values;
        },
        async deleteSegment(uuid) {
            segments.delete(uuid);
        },
        async enqueueJob(job) {
            const value = {
                attempts: 0,
                createdAt: Date.now(),
                nextAttemptAt: 0,
                status: 'queued',
                ...job,
                id: job.id || crypto.randomUUID(),
                updatedAt: Date.now(),
            };
            jobs.set(value.id, value);
            return value;
        },
        async listJobs(status = null) {
            return [...jobs.values()]
                .filter((item) => !status || item.status === status)
                .sort((a, b) => (a.createdAt ?? 0) - (b.createdAt ?? 0));
        },
        async updateJob(id, patch) {
            const current = jobs.get(id);
            if (!current) return null;
            const value = { ...current, ...patch, id, updatedAt: Date.now() };
            jobs.set(id, value);
            return value;
        },
        async getSession(id = 'current') {
            return sessions.get(id) || null;
        },
        async putSession(session, id = 'current') {
            const value = { ...session, id, updatedAt: Date.now() };
            sessions.set(id, value);
            return value;
        },
        async clearSession(id = 'current') {
            sessions.delete(id);
        },
        async markProcessed(key, metadata = {}) {
            const value = { ...metadata, key, processedAt: Date.now() };
            processed.set(key, value);
            return value;
        },
        async wasProcessed(key) {
            return processed.has(key);
        },
    };
}

export function useRecSegmentStore(options = {}) {
    const preferMemory = options.memoryOnly === true
        || (options.memoryOnly !== false && isAppleMobile());

    const memory = createMemoryBackend();
    let databasePromise;
    let useMemory = preferMemory;

    function database() {
        if (useMemory) {
            return Promise.reject(new Error('IndexedDB skipped (memory mode)'));
        }

        databasePromise ||= openDatabase().catch((error) => {
            useMemory = true;
            databasePromise = null;
            throw error;
        });

        return databasePromise;
    }

    async function useStore(name, mode, callback) {
        const db = await database();
        const transaction = db.transaction(name, mode);
        const result = await callback(transaction.objectStore(name));
        await withTimeout(transactionDone(transaction), `IndexedDB ${name}`);
        return result;
    }

    async function withFallback(memoryFn, idbFn) {
        if (useMemory) return memoryFn();
        try {
            return await idbFn();
        } catch {
            useMemory = true;
            return memoryFn();
        }
    }

    async function putSegment(segment) {
        return withFallback(
            () => memory.putSegment(segment),
            async () => {
                const value = {
                    createdAt: Date.now(),
                    ...segment,
                    uuid: segment.uuid || crypto.randomUUID(),
                };
                await useStore('segments', 'readwrite', (store) => requestAsPromise(store.put(value)));
                return value;
            },
        );
    }

    async function getSegments(sessionUuid = null) {
        return withFallback(
            () => memory.getSegments(sessionUuid),
            () => useStore('segments', 'readonly', async (store) => {
                const values = sessionUuid
                    ? await requestAsPromise(store.index('sessionUuid').getAll(sessionUuid))
                    : await requestAsPromise(store.getAll());

                return values.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0));
            }),
        );
    }

    async function deleteSegment(uuid) {
        return withFallback(
            () => memory.deleteSegment(uuid),
            () => useStore('segments', 'readwrite', (store) => requestAsPromise(store.delete(uuid))),
        );
    }

    async function enqueueJob(job) {
        return withFallback(
            () => memory.enqueueJob(job),
            async () => {
                const value = {
                    attempts: 0,
                    createdAt: Date.now(),
                    nextAttemptAt: 0,
                    status: 'queued',
                    ...job,
                    id: job.id || crypto.randomUUID(),
                    updatedAt: Date.now(),
                };
                await useStore('uploadJobs', 'readwrite', (store) => requestAsPromise(store.put(value)));
                return value;
            },
        );
    }

    async function listJobs(status = null) {
        return withFallback(
            () => memory.listJobs(status),
            () => useStore('uploadJobs', 'readonly', async (store) => {
                const values = status
                    ? await requestAsPromise(store.index('status').getAll(status))
                    : await requestAsPromise(store.getAll());
                return values.sort((a, b) => (a.createdAt ?? 0) - (b.createdAt ?? 0));
            }),
        );
    }

    async function updateJob(id, patch) {
        return withFallback(
            () => memory.updateJob(id, patch),
            () => useStore('uploadJobs', 'readwrite', async (store) => {
                const current = await requestAsPromise(store.get(id));
                if (!current) return null;

                const value = { ...current, ...patch, id, updatedAt: Date.now() };
                await requestAsPromise(store.put(value));
                return value;
            }),
        );
    }

    async function getSession(id = 'current') {
        return withFallback(
            () => memory.getSession(id),
            () => useStore('sessionMeta', 'readonly', (store) => requestAsPromise(store.get(id))),
        );
    }

    async function putSession(session, id = 'current') {
        return withFallback(
            () => memory.putSession(session, id),
            async () => {
                const value = { ...session, id, updatedAt: Date.now() };
                await useStore('sessionMeta', 'readwrite', (store) => requestAsPromise(store.put(value)));
                return value;
            },
        );
    }

    async function clearSession(id = 'current') {
        return withFallback(
            () => memory.clearSession(id),
            () => useStore('sessionMeta', 'readwrite', (store) => requestAsPromise(store.delete(id))),
        );
    }

    async function markProcessed(key, metadata = {}) {
        return withFallback(
            () => memory.markProcessed(key, metadata),
            async () => {
                const value = { ...metadata, key, processedAt: Date.now() };
                await useStore('processedKeys', 'readwrite', (store) => requestAsPromise(store.put(value)));
                return value;
            },
        );
    }

    async function wasProcessed(key) {
        return withFallback(
            () => memory.wasProcessed(key),
            async () => {
                const value = await useStore(
                    'processedKeys',
                    'readonly',
                    (store) => requestAsPromise(store.get(key)),
                );
                return !!value;
            },
        );
    }

    return {
        putSegment,
        getSegments,
        deleteSegment,
        enqueueJob,
        listJobs,
        updateJob,
        getSession,
        putSession,
        clearSession,
        markProcessed,
        wasProcessed,
        isMemoryMode: () => useMemory,
    };
}

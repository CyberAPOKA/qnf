const DB_NAME = 'qnf-rec';
const DB_VERSION = 1;

function requestAsPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transactionDone(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
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

    return new Promise((resolve, reject) => {
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
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Atualização do IndexedDB bloqueada.'));
    });
}

export function useRecSegmentStore() {
    let databasePromise;

    function database() {
        databasePromise ||= openDatabase();
        return databasePromise;
    }

    async function useStore(name, mode, callback) {
        const db = await database();
        const transaction = db.transaction(name, mode);
        const result = await callback(transaction.objectStore(name));
        await transactionDone(transaction);
        return result;
    }

    async function putSegment(segment) {
        const value = {
            createdAt: Date.now(),
            ...segment,
            uuid: segment.uuid || crypto.randomUUID(),
        };
        await useStore('segments', 'readwrite', (store) => requestAsPromise(store.put(value)));
        return value;
    }

    async function getSegments(sessionUuid = null) {
        return useStore('segments', 'readonly', async (store) => {
            const values = sessionUuid
                ? await requestAsPromise(store.index('sessionUuid').getAll(sessionUuid))
                : await requestAsPromise(store.getAll());

            return values.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0));
        });
    }

    async function deleteSegment(uuid) {
        await useStore('segments', 'readwrite', (store) => requestAsPromise(store.delete(uuid)));
    }

    async function enqueueJob(job) {
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
    }

    async function listJobs(status = null) {
        return useStore('uploadJobs', 'readonly', async (store) => {
            const values = status
                ? await requestAsPromise(store.index('status').getAll(status))
                : await requestAsPromise(store.getAll());
            return values.sort((a, b) => (a.createdAt ?? 0) - (b.createdAt ?? 0));
        });
    }

    async function updateJob(id, patch) {
        return useStore('uploadJobs', 'readwrite', async (store) => {
            const current = await requestAsPromise(store.get(id));
            if (!current) return null;

            const value = { ...current, ...patch, id, updatedAt: Date.now() };
            await requestAsPromise(store.put(value));
            return value;
        });
    }

    async function getSession(id = 'current') {
        return useStore('sessionMeta', 'readonly', (store) => requestAsPromise(store.get(id)));
    }

    async function putSession(session, id = 'current') {
        const value = { ...session, id, updatedAt: Date.now() };
        await useStore('sessionMeta', 'readwrite', (store) => requestAsPromise(store.put(value)));
        return value;
    }

    async function clearSession(id = 'current') {
        await useStore('sessionMeta', 'readwrite', (store) => requestAsPromise(store.delete(id)));
    }

    async function markProcessed(key, metadata = {}) {
        const value = { ...metadata, key, processedAt: Date.now() };
        await useStore('processedKeys', 'readwrite', (store) => requestAsPromise(store.put(value)));
        return value;
    }

    async function wasProcessed(key) {
        const value = await useStore(
            'processedKeys',
            'readonly',
            (store) => requestAsPromise(store.get(key)),
        );
        return !!value;
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
    };
}

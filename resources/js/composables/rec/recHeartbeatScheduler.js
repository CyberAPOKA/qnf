/**
 * Tiny timer worker so REC keep-alive scheduling is not starved by MediaRecorder work on the main thread.
 */
export function createRecHeartbeatScheduler(onTick) {
    let worker = null;
    let fallbackTimer = null;
    let running = false;

    function stop() {
        running = false;
        if (fallbackTimer) {
            clearInterval(fallbackTimer);
            fallbackTimer = null;
        }
        if (worker) {
            try {
                worker.postMessage({ type: 'stop' });
                worker.terminate();
            } catch {
                // ignore
            }
            worker = null;
        }
    }

    function start(intervalMs) {
        stop();
        running = true;
        const ms = Math.max(3_000, Number(intervalMs) || 10_000);

        try {
            const source = `
                let timer = null;
                onmessage = (event) => {
                    if (event.data?.type === 'start') {
                        clearInterval(timer);
                        timer = setInterval(() => postMessage({ type: 'tick' }), event.data.intervalMs);
                    }
                    if (event.data?.type === 'stop') {
                        clearInterval(timer);
                        timer = null;
                    }
                };
            `;
            const blobUrl = URL.createObjectURL(new Blob([source], { type: 'application/javascript' }));
            worker = new Worker(blobUrl);
            worker.onmessage = (event) => {
                if (event.data?.type === 'tick' && running) onTick();
            };
            worker.onerror = () => {
                if (!running || fallbackTimer) return;
                fallbackTimer = setInterval(() => {
                    if (running) onTick();
                }, ms);
            };
            worker.postMessage({ type: 'start', intervalMs: ms });
            // Worker copies the script; object URL can be released.
            setTimeout(() => URL.revokeObjectURL(blobUrl), 0);
        } catch {
            fallbackTimer = setInterval(() => {
                if (running) onTick();
            }, ms);
        }
    }

    return { start, stop };
}

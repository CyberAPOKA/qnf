export function useAudioPlayer() {
    let audio = null;
    let intervalId = null;
    let objectUrl = null;
    let segmentStart = 0;
    let segmentEnd = 0;
    let onTimeUpdateCallback = null;
    let onPlayStateChangeCallback = null;
    let onSegmentEndCallback = null;

    const clearWatchInterval = () => {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    };

    const watchSegment = () => {
        clearWatchInterval();

        intervalId = setInterval(() => {
            if (!audio) {
                return;
            }

            onTimeUpdateCallback?.(audio.currentTime);

            if (audio.currentTime >= segmentEnd - 0.15) {
                audio.pause();
                audio.currentTime = segmentStart;
                onSegmentEndCallback?.();
                onPlayStateChangeCallback?.(false);
            }
        }, 100);
    };

    const revokeObjectUrl = () => {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    };

    const resolveFetchUrl = (source) => {
        if (typeof source !== 'string') {
            return source;
        }

        try {
            const parsed = new URL(source, window.location.origin);

            if (parsed.pathname.startsWith('/storage/')) {
                return parsed.pathname;
            }
        } catch {
            // Keep original source when it is already a relative path.
        }

        return source;
    };

    const resolveSourceUrl = async (source) => {
        if (source instanceof File) {
            objectUrl = URL.createObjectURL(source);
            return objectUrl;
        }

        if (typeof source === 'string') {
            const fetchUrl = resolveFetchUrl(source);
            const response = await fetch(fetchUrl, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error('Não foi possível carregar o arquivo MP3.');
            }

            const blob = await response.blob();
            objectUrl = URL.createObjectURL(blob);

            return objectUrl;
        }

        throw new Error('Fonte de áudio inválida.');
    };

    const waitForEvent = (element, eventName, timeoutMs = 5000) => new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            element.removeEventListener(eventName, onEvent);
            reject(new Error(`Timeout aguardando ${eventName}.`));
        }, timeoutMs);

        const onEvent = () => {
            clearTimeout(timeout);
            element.removeEventListener(eventName, onEvent);
            resolve();
        };

        element.addEventListener(eventName, onEvent);
    });

    const seekToAsync = async (seconds) => {
        if (!audio) {
            return false;
        }

        const target = Math.max(0, seconds);

        if (Math.abs(audio.currentTime - target) < 0.25) {
            return true;
        }

        for (let attempt = 0; attempt < 3; attempt += 1) {
            await new Promise((resolve) => {
                let settled = false;

                const finish = () => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    audio.removeEventListener('seeked', onSeeked);
                    clearTimeout(fallback);
                    resolve();
                };

                const onSeeked = () => finish();
                const fallback = setTimeout(finish, 300);

                audio.addEventListener('seeked', onSeeked);
                audio.currentTime = target;
            });

            if (Math.abs(audio.currentTime - target) < 0.5) {
                return true;
            }

            await new Promise((resolve) => setTimeout(resolve, 50));
        }

        return Math.abs(audio.currentTime - target) < 1;
    };

    const init = async (source, { start = 0, end = 0, onReady, onTimeUpdate, onPlayStateChange, onSegmentEnd } = {}) => {
        destroy();

        segmentStart = start;
        segmentEnd = end;
        onTimeUpdateCallback = onTimeUpdate;
        onPlayStateChangeCallback = onPlayStateChange;
        onSegmentEndCallback = onSegmentEnd;

        audio = new Audio();
        audio.preload = 'auto';

        const src = await resolveSourceUrl(source);
        audio.src = src;

        if (audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) {
            await waitForEvent(audio, 'canplaythrough').catch(() => waitForEvent(audio, 'canplay'));
        }

        await seekToAsync(segmentStart);

        audio.addEventListener('play', () => {
            onPlayStateChangeCallback?.(true);
            watchSegment();
        });

        audio.addEventListener('pause', () => {
            onPlayStateChangeCallback?.(false);
            clearWatchInterval();
            onTimeUpdateCallback?.(audio.currentTime);
        });

        onReady?.(audio);

        return audio;
    };

    const playPreview = async () => {
        if (!audio) {
            return;
        }

        const current = audio.currentTime;

        if (current < segmentStart - 0.25 || current >= segmentEnd - 0.15) {
            await seekToAsync(segmentStart);
        }

        await audio.play();
    };

    const playSegment = async () => {
        if (!audio) {
            return;
        }

        await seekToAsync(segmentStart);
        await audio.play();
    };

    const pausePreview = () => audio?.pause();

    const seekTo = async (seconds) => {
        await seekToAsync(seconds);
    };

    const seekToStart = async () => {
        await seekToAsync(segmentStart);
    };

    const getCurrentTime = () => audio?.currentTime ?? 0;

    const getDuration = () => audio?.duration ?? 0;

    const isPlaying = () => audio ? !audio.paused : false;

    const setSegment = (start, end) => {
        segmentStart = start;
        segmentEnd = end;
    };

    const destroy = () => {
        clearWatchInterval();

        if (audio) {
            audio.pause();
            audio.removeAttribute('src');
            audio.load();
            audio = null;
        }

        revokeObjectUrl();
    };

    return {
        init,
        playPreview,
        playSegment,
        pausePreview,
        seekTo,
        seekToStart,
        getCurrentTime,
        getDuration,
        isPlaying,
        setSegment,
        destroy,
    };
}

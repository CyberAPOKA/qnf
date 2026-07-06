let apiLoadingPromise = null;

function loadYouTubeApi() {
    if (window.YT?.Player) {
        return Promise.resolve();
    }

    if (apiLoadingPromise) {
        return apiLoadingPromise;
    }

    apiLoadingPromise = new Promise((resolve) => {
        window.onYouTubeIframeAPIReady = () => resolve();

        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
    });

    return apiLoadingPromise;
}

const EMBED_ERROR_CODES = new Set([2, 5, 100, 101, 150]);

/**
 * Valida no browser (IFrame Player API) se o vídeo realmente permite embed.
 * A Data API frequentemente retorna embeddable=true para vídeos que o player recusa.
 */
export async function filterEmbeddableVideos(videos, { concurrency = 4, timeoutMs = 8000 } = {}) {
    if (!videos.length) {
        return [];
    }

    await loadYouTubeApi();

    const embeddable = [];
    const queue = [...videos];

    const validateOne = (video) => new Promise((resolve) => {
        const elementId = `yt-embed-check-${video.id}-${Math.random().toString(36).slice(2)}`;
        const container = document.createElement('div');
        container.id = elementId;
        container.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;';
        document.body.appendChild(container);

        let settled = false;
        let errored = false;
        let player = null;

        const finish = (accepted) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timeoutId);

            try {
                player?.destroy?.();
            } catch {
                // noop
            }

            container.remove();
            resolve(accepted ? video : null);
        };

        const timeoutId = setTimeout(() => finish(false), timeoutMs);

        player = new window.YT.Player(elementId, {
            videoId: video.id,
            width: 1,
            height: 1,
            playerVars: {
                autoplay: 0,
                controls: 0,
                modestbranding: 1,
                rel: 0,
                enablejsapi: 1,
                origin: window.location.origin,
            },
            events: {
                onReady: () => {
                    setTimeout(() => {
                        if (!errored) {
                            finish(true);
                        }
                    }, 1500);
                },
                onError: (event) => {
                    if (EMBED_ERROR_CODES.has(event.data)) {
                        errored = true;
                        finish(false);
                    }
                },
            },
        });
    });

    while (queue.length) {
        const batch = queue.splice(0, concurrency);
        const batchResults = await Promise.all(batch.map(validateOne));
        embeddable.push(...batchResults.filter(Boolean));
    }

    return embeddable;
}

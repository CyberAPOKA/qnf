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

function resetContainer(elementId) {
    const container = document.getElementById(elementId);

    if (container) {
        container.innerHTML = '';
    }
}

export function formatSeconds(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;

    return `${minutes}:${remainder.toString().padStart(2, '0')}`;
}

export function useYouTubePlayer() {
    let player = null;
    let intervalId = null;
    let onPlayerReadyCallback = null;
    let onPlayerStateChangeCallback = null;
    let onErrorCallback = null;
    let onSegmentEndCallback = null;
    let segmentStart = 0;
    let segmentEnd = 0;
    let currentElementId = null;

    const clearWatchInterval = () => {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    };

    const watchSegment = () => {
        clearWatchInterval();

        intervalId = setInterval(() => {
            if (!player?.getCurrentTime) {
                return;
            }

            const current = player.getCurrentTime();

            if (current >= segmentEnd - 0.15) {
                player.pauseVideo();
                player.seekTo(segmentStart, true);
                onSegmentEndCallback?.();
            }
        }, 100);
    };

    const init = async (elementId, videoId, { start = 0, end = 0, onReady, onStateChange, onError, onSegmentEnd } = {}) => {
        await loadYouTubeApi();

        segmentStart = start;
        segmentEnd = end;
        onPlayerReadyCallback = onReady;
        onPlayerStateChangeCallback = onStateChange;
        onErrorCallback = onError;
        onSegmentEndCallback = onSegmentEnd;
        currentElementId = elementId;

        if (player?.destroy) {
            player.destroy();
            player = null;
        }

        clearWatchInterval();
        resetContainer(elementId);

        return new Promise((resolve) => {
            player = new window.YT.Player(elementId, {
                videoId,
                width: '100%',
                height: '100%',
                playerVars: {
                    controls: 1,
                    modestbranding: 1,
                    rel: 0,
                    start,
                    enablejsapi: 1,
                    origin: window.location.origin,
                    playsinline: 1,
                },
                events: {
                    onReady: (event) => {
                        event.target.seekTo(segmentStart, true);
                        onPlayerReadyCallback?.(event);
                        resolve(event.target);
                    },
                    onStateChange: (event) => {
                        if (event.data === window.YT.PlayerState.PLAYING) {
                            watchSegment();
                        } else if (event.data === window.YT.PlayerState.PAUSED) {
                            clearWatchInterval();
                        }

                        onPlayerStateChangeCallback?.(event);
                    },
                    onError: (event) => {
                        const messages = {
                            2: 'Parâmetro inválido do player.',
                            5: 'Erro de reprodução HTML5.',
                            100: 'Vídeo não encontrado.',
                            101: 'Este vídeo não permite reprodução incorporada.',
                            150: 'Este vídeo não permite reprodução incorporada.',
                        };

                        onErrorCallback?.(messages[event.data] ?? 'Não foi possível reproduzir este vídeo.');
                    },
                },
            });
        });
    };

    const playPreview = () => {
        if (!player?.seekTo) {
            return;
        }

        const current = player.getCurrentTime?.() ?? segmentStart;

        if (current < segmentStart || current >= segmentEnd - 0.15) {
            player.seekTo(segmentStart, true);
        }

        player.playVideo();
    };

    const playSegment = () => {
        if (!player?.seekTo || !player?.playVideo) {
            return;
        }

        player.seekTo(segmentStart, true);

        window.setTimeout(() => {
            if (!player?.seekTo) {
                return;
            }

            player.seekTo(segmentStart, true);
            player.playVideo();
        }, 100);
    };

    const pausePreview = () => player?.pauseVideo?.();

    const seekTo = (seconds) => {
        if (!player?.seekTo) {
            return;
        }

        player.seekTo(seconds, true);
    };

    const seekToStart = () => {
        seekTo(segmentStart);
    };

    const getCurrentTime = () => player?.getCurrentTime?.() ?? 0;

    const getDuration = () => player?.getDuration?.() ?? 0;

    const isPlaying = () => player?.getPlayerState?.() === window.YT?.PlayerState?.PLAYING;

    const setSegment = (start, end) => {
        segmentStart = start;
        segmentEnd = end;
    };

    const destroy = () => {
        clearWatchInterval();

        if (player?.destroy) {
            player.destroy();
        }

        if (currentElementId) {
            resetContainer(currentElementId);
        }

        player = null;
        currentElementId = null;
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

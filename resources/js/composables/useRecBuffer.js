import { ref, onBeforeUnmount, nextTick } from 'vue';

const BUFFER_SECONDS = 30;
const TIMESLICE_MS = 1000;
const MAX_CHUNKS = BUFFER_SECONDS + 2;

function pickMimeType() {
    const candidates = [
        'video/webm;codecs=vp8,opus',
        'video/webm;codecs=vp8',
        'video/webm',
        'video/mp4',
    ];

    return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

export function useRecBuffer() {
    const isRecording = ref(false);
    const isSupported = ref(
        typeof window !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia
        && typeof MediaRecorder !== 'undefined',
    );
    const error = ref(null);
    const previewEl = ref(null);

    let mediaStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    const chunks = [];

    function attachPreview(stream) {
        if (previewEl.value) {
            previewEl.value.srcObject = stream;
            previewEl.value.muted = true;
            previewEl.value.playsInline = true;
            previewEl.value.play().catch(() => {});
        }
    }

    function trimChunks() {
        while (chunks.length > MAX_CHUNKS) {
            chunks.shift();
        }
    }

    function handleDataAvailable(event) {
        if (!event.data || event.data.size === 0) {
            return;
        }

        chunks.push(event.data);
        trimChunks();
    }

    async function start() {
        error.value = null;

        if (!isSupported.value) {
            error.value = 'Gravação não suportada neste navegador.';
            return false;
        }

        if (isRecording.value) {
            return true;
        }

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 24, max: 30 },
                },
            });

            attachPreview(mediaStream);

            mimeType = pickMimeType();
            const options = {
                videoBitsPerSecond: 1_200_000,
                audioBitsPerSecond: 96_000,
            };

            if (mimeType) {
                options.mimeType = mimeType;
            }

            mediaRecorder = new MediaRecorder(mediaStream, options);
            mediaRecorder.ondataavailable = handleDataAvailable;
            mediaRecorder.onerror = () => {
                error.value = 'Erro na gravação.';
            };

            chunks.length = 0;
            mediaRecorder.start(TIMESLICE_MS);
            isRecording.value = true;

            await nextTick();
            attachPreview(mediaStream);

            return true;
        } catch (err) {
            error.value = err?.name === 'NotAllowedError'
                ? 'Permissão da câmera negada.'
                : 'Não foi possível acessar a câmera.';
            stop();

            return false;
        }
    }

    function snapshot() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            try {
                mediaRecorder.requestData();
            } catch {
                // ignore — some browsers throw if called too early
            }
        }

        if (!chunks.length) {
            return null;
        }

        const type = (mimeType || chunks[0]?.type || 'video/webm').split(';')[0];

        return new Blob(chunks.slice(), { type });
    }

    function hasBuffer() {
        return chunks.length > 0;
    }

    function stop() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }

        mediaRecorder = null;
        chunks.length = 0;

        if (mediaStream) {
            mediaStream.getTracks().forEach((track) => track.stop());
            mediaStream = null;
        }

        if (previewEl.value) {
            previewEl.value.srcObject = null;
        }

        isRecording.value = false;
    }

    onBeforeUnmount(() => {
        stop();
    });

    return {
        isRecording,
        isSupported,
        error,
        previewEl,
        start,
        stop,
        snapshot,
        hasBuffer,
        bufferSeconds: BUFFER_SECONDS,
    };
}

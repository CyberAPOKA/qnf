<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { CLIP_DURATION_OPTIONS } from '@/composables/useMusicClip';
import { formatSeconds } from '@/composables/useYouTubePlayer';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    totalDuration: { type: Number, required: true },
    clipDuration: { type: Number, required: true },
    startSecond: { type: Number, required: true },
    endSecond: { type: Number, required: true },
    currentTime: { type: Number, default: 0 },
    playerReady: { type: Boolean, default: false },
    isPlaying: { type: Boolean, default: false },
    validationError: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits([
    'toggle-playback',
    'duration-change',
    'start-change',
]);

const selectedDuration = defineModel('selectedDuration', { type: Number, required: true });

const BASE_PX_PER_SECOND = 12;
const MIN_PX_PER_SECOND = 5;
const FRAME_VIEWPORT_RATIO = 0.9;

const scrollRef = ref(null);
const viewportRef = ref(null);
const viewportWidth = ref(0);
const isDraggingTrack = ref(false);
const dragStartX = ref(0);
const dragStartScrollLeft = ref(0);
const suppressScrollEmit = ref(false);

const pxPerSecond = computed(() => {
    const viewport = viewportWidth.value;

    if (!viewport || !props.clipDuration) {
        return BASE_PX_PER_SECOND;
    }

    const maxFrameWidth = viewport * FRAME_VIEWPORT_RATIO;
    const idealFrameWidth = props.clipDuration * BASE_PX_PER_SECOND;
    const frameWidth = Math.min(idealFrameWidth, maxFrameWidth);

    return Math.max(MIN_PX_PER_SECOND, frameWidth / props.clipDuration);
});

const frameWidthPx = computed(() => props.clipDuration * pxPerSecond.value);

const trackWidthPx = computed(() => Math.max(props.totalDuration * pxPerSecond.value, frameWidthPx.value));

const waveformBarWidth = computed(() => (viewportWidth.value <= 640 ? 4 : 3));
const waveformBarGap = computed(() => (viewportWidth.value <= 640 ? 2 : 1));

const barCount = computed(() => {
    if (!props.totalDuration) {
        return 0;
    }

    const barUnit = waveformBarWidth.value + waveformBarGap.value;

    return Math.max(1, Math.floor(trackWidthPx.value / barUnit));
});

const sidePaddingPx = computed(() => Math.max(0, (viewportWidth.value - frameWidthPx.value) / 2));

const segmentLeftPercent = computed(() => {
    if (!props.totalDuration) {
        return 0;
    }

    return (props.startSecond / props.totalDuration) * 100;
});

const segmentWidthPercent = computed(() => {
    if (!props.totalDuration) {
        return 0;
    }

    return (props.clipDuration / props.totalDuration) * 100;
});

const playheadPercentInFrame = computed(() => {
    if (!props.clipDuration) {
        return 0;
    }

    const relative = props.currentTime - props.startSecond;

    return Math.max(0, Math.min(100, (relative / props.clipDuration) * 100));
});

const barHeight = (index) => {
    const wave = Math.sin(index * 0.45) * 0.35 + Math.sin(index * 0.17) * 0.25;
    const normalized = 0.35 + ((wave + 1) / 2) * 0.55;

    return `${Math.round(normalized * 100)}%`;
};

const syncScrollFromStart = () => {
    const element = scrollRef.value;

    if (!element) {
        return;
    }

    suppressScrollEmit.value = true;
    element.scrollLeft = props.startSecond * pxPerSecond.value;
    requestAnimationFrame(() => {
        suppressScrollEmit.value = false;
    });
};

const emitStartChange = (start, play = false) => {
    emit('start-change', {
        start: Math.round(start),
        play,
    });
};

const onTrackScroll = () => {
    if (suppressScrollEmit.value || !scrollRef.value) {
        return;
    }

    emitStartChange(scrollRef.value.scrollLeft / pxPerSecond.value, isDraggingTrack.value);
};

const onTrackPointerDown = (event) => {
    if (props.disabled || !props.playerReady || !scrollRef.value) {
        return;
    }

    isDraggingTrack.value = true;
    dragStartX.value = event.clientX;
    dragStartScrollLeft.value = scrollRef.value.scrollLeft;
    scrollRef.value.setPointerCapture(event.pointerId);
    event.preventDefault();
};

const onTrackPointerMove = (event) => {
    if (!isDraggingTrack.value || !scrollRef.value) {
        return;
    }

    scrollRef.value.scrollLeft = dragStartScrollLeft.value - (event.clientX - dragStartX.value);
};

const finishTrackDrag = (event) => {
    if (!isDraggingTrack.value || !scrollRef.value) {
        return;
    }

    isDraggingTrack.value = false;

    try {
        scrollRef.value.releasePointerCapture(event.pointerId);
    } catch {
        // Pointer may already be released.
    }

    emitStartChange(scrollRef.value.scrollLeft / pxPerSecond.value, true);
};

const measureViewport = () => {
    viewportWidth.value = viewportRef.value?.clientWidth ?? 0;
};

let resizeObserver = null;

onMounted(async () => {
    measureViewport();

    if (viewportRef.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(measureViewport);
        resizeObserver.observe(viewportRef.value);
    }

    await nextTick();
    syncScrollFromStart();
});

onUnmounted(() => {
    resizeObserver?.disconnect();
});

watch(
    () => [props.startSecond, props.totalDuration, props.clipDuration, viewportWidth.value, pxPerSecond.value],
    async () => {
        await nextTick();
        syncScrollFromStart();
    },
);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="font-semibold text-gray-900">{{ title }}</h3>
            <p v-if="subtitle" class="text-sm text-gray-500">{{ subtitle }}</p>
            <p class="mt-1 text-sm text-gray-600">
                Duração total:
                <span class="font-mono">{{ formatSeconds(totalDuration) }}</span>
            </p>
        </div>

        <slot name="player" />

        <div v-if="totalDuration > 0" class="music-clip-editor space-y-3">
            <div class="flex items-center gap-2 sm:gap-3">
                <label class="relative shrink-0">
                    <span class="sr-only">Duração do trecho</span>
                    <select
                        v-model="selectedDuration"
                        class="music-clip-duration-select"
                        :disabled="disabled || !playerReady"
                        @change="$emit('duration-change', selectedDuration)"
                    >
                        <option
                            v-for="option in CLIP_DURATION_OPTIONS"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}s
                        </option>
                    </select>
                    <span class="music-clip-duration-badge" aria-hidden="true">{{ clipDuration }}</span>
                </label>

                <div class="min-w-0 flex-1 overflow-visible py-2">
                    <div class="music-clip-overview-track">
                        <div
                            class="music-clip-overview-segment"
                            :style="{
                                left: `${segmentLeftPercent}%`,
                                width: `${segmentWidthPercent}%`,
                            }"
                        />
                        <div
                            class="music-clip-overview-bar music-clip-overview-bar--start"
                            :style="{ left: `${segmentLeftPercent}%` }"
                        />
                        <div
                            class="music-clip-overview-bar music-clip-overview-bar--end"
                            :style="{ left: `${segmentLeftPercent + segmentWidthPercent}%` }"
                        />
                    </div>
                </div>

                <button
                    type="button"
                    class="music-clip-play-btn shrink-0"
                    :disabled="!playerReady"
                    :aria-label="isPlaying ? 'Pausar' : 'Reproduzir'"
                    @click="$emit('toggle-playback')"
                >
                    <i :class="isPlaying ? 'fa-solid fa-pause' : 'fa-solid fa-play'" />
                </button>
            </div>

            <div
                ref="viewportRef"
                class="music-clip-viewport"
                :class="{ 'music-clip-viewport--dragging': isDraggingTrack }"
            >
                <div
                    class="music-clip-frame"
                    :style="{ width: `${frameWidthPx}px` }"
                >
                    <div class="music-clip-frame-gradient" />
                    <div class="music-clip-frame-bar music-clip-frame-bar--start" />
                    <div class="music-clip-frame-bar music-clip-frame-bar--end" />
                    <div
                        v-if="playerReady && isPlaying"
                        class="music-clip-frame-playhead"
                        :style="{ left: `${playheadPercentInFrame}%` }"
                    />
                </div>

                <div
                    ref="scrollRef"
                    class="music-clip-scroll"
                    @scroll="onTrackScroll"
                    @pointerdown="onTrackPointerDown"
                    @pointermove="onTrackPointerMove"
                    @pointerup="finishTrackDrag"
                    @pointercancel="finishTrackDrag"
                >
                    <div
                        class="music-clip-track"
                        :style="{
                            width: `${trackWidthPx + sidePaddingPx * 2}px`,
                            paddingLeft: `${sidePaddingPx}px`,
                            paddingRight: `${sidePaddingPx}px`,
                        }"
                    >
                        <div
                            class="music-clip-waveform"
                            :style="{
                                width: `${trackWidthPx}px`,
                                gap: `${waveformBarGap}px`,
                            }"
                        >
                            <span
                                v-for="index in barCount"
                                :key="index"
                                class="music-clip-waveform-bar"
                                :style="{
                                    width: `${waveformBarWidth}px`,
                                    height: barHeight(index),
                                }"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs sm:text-sm text-gray-500 font-mono gap-2">
                <span>{{ formatSeconds(startSecond) }}</span>
                <span>{{ formatSeconds(currentTime) }} / {{ formatSeconds(endSecond) }}</span>
                <span>{{ formatSeconds(totalDuration) }}</span>
            </div>

            <p class="text-xs text-gray-500">
                Escolha a duração e arraste a faixa para posicionar o trecho.
            </p>
        </div>

        <p v-if="validationError" class="text-sm text-red-600">{{ validationError }}</p>
    </div>
</template>

<style scoped>
.music-clip-editor {
    --clip-control-size: 3rem;
    --clip-overview-bar-width: 3px;
    --clip-overview-bar-height: 1.1rem;
    --clip-frame-bar-width: 4px;
    --clip-viewport-height: 7.5rem;
    --clip-frame-height: 5rem;
    --clip-waveform-height: 4rem;
}

@media (min-width: 640px) {
    .music-clip-editor {
        --clip-control-size: 2.75rem;
        --clip-overview-bar-width: 2px;
        --clip-overview-bar-height: 0.85rem;
        --clip-frame-bar-width: 3px;
        --clip-viewport-height: 6.5rem;
        --clip-frame-height: 4.5rem;
        --clip-waveform-height: 3.5rem;
    }
}

.music-clip-duration-select {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.music-clip-duration-select:disabled {
    cursor: not-allowed;
}

.music-clip-duration-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--clip-control-size);
    height: var(--clip-control-size);
    border-radius: 9999px;
    background: #fff;
    border: 2px solid #e5e7eb;
    font-size: 1rem;
    font-weight: 700;
    color: #111827;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
    pointer-events: none;
}

.music-clip-overview-track {
    position: relative;
    height: 0.5rem;
    border-radius: 9999px;
    background: #374151;
    overflow: visible;
}

@media (min-width: 640px) {
    .music-clip-overview-track {
        height: 0.35rem;
    }
}

.music-clip-overview-segment {
    position: absolute;
    top: 0;
    bottom: 0;
    border-radius: 9999px;
    background: #fbbf24;
}

.music-clip-overview-bar {
    position: absolute;
    top: 50%;
    z-index: 2;
    width: var(--clip-overview-bar-width);
    height: var(--clip-overview-bar-height);
    background: #ec4899;
    border-radius: 2px;
    box-shadow: 0 0 0 1px rgb(236 72 153 / 0.35);
    transform: translate(-50%, -50%);
}

.music-clip-play-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--clip-control-size);
    height: var(--clip-control-size);
    border-radius: 9999px;
    background: #fff;
    border: 2px solid #e5e7eb;
    color: #111827;
    font-size: 1rem;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
}

.music-clip-play-btn:disabled {
    opacity: 0.5;
}

.music-clip-viewport {
    position: relative;
    height: var(--clip-viewport-height);
    border-radius: 0.75rem;
    overflow: hidden;
    background: #111827;
}

.music-clip-scroll {
    height: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    cursor: grab;
    touch-action: pan-x;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}

.music-clip-scroll::-webkit-scrollbar {
    display: none;
}

.music-clip-viewport--dragging .music-clip-scroll {
    cursor: grabbing;
}

.music-clip-track {
    display: flex;
    align-items: center;
    height: 100%;
    min-height: var(--clip-viewport-height);
}

.music-clip-waveform {
    display: flex;
    align-items: flex-end;
    gap: var(--clip-waveform-gap, 2px);
    height: var(--clip-waveform-height);
}

@media (min-width: 640px) {
    .music-clip-waveform {
        gap: 1px;
    }
}

.music-clip-waveform-bar {
    flex-shrink: 0;
    border-radius: 9999px;
    background: rgb(255 255 255 / 0.72);
}

.music-clip-frame {
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 2;
    height: var(--clip-frame-height);
    max-width: calc(100% - 0.5rem);
    pointer-events: none;
    transform: translate(-50%, -50%);
}

.music-clip-frame-gradient {
    position: absolute;
    inset: 0;
    border: 2px solid #fff;
    border-radius: 0.65rem;
    background: linear-gradient(90deg, rgb(249 115 22 / 0.45), rgb(236 72 153 / 0.45));
    box-shadow: 0 0 0 1px rgb(255 255 255 / 0.15);
}

.music-clip-frame-bar {
    position: absolute;
    top: 0.2rem;
    bottom: 0.2rem;
    width: var(--clip-frame-bar-width);
    background: #fff;
    border-radius: 2px;
    box-shadow: 0 0 0 1px rgb(255 255 255 / 0.35);
}

.music-clip-frame-bar--start {
    left: 4px;
}

.music-clip-frame-bar--end {
    right: 4px;
}

.music-clip-frame-playhead {
    position: absolute;
    top: 0.2rem;
    bottom: 0.2rem;
    width: 3px;
    background: #fbbf24;
    border-radius: 2px;
    transform: translateX(-50%);
    box-shadow: 0 0 0 1px rgb(251 191 36 / 0.35);
}
</style>

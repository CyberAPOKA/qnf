<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MusicClipEditor from '@/Components/Music/MusicClipEditor.vue';
import { useAudioPlayer } from '@/composables/useAudioPlayer';
import { useMusicClip } from '@/composables/useMusicClip';

const props = defineProps({
    modelValue: {
        type: Object,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'selected']);

const selectedFile = ref(null);
const existingFileUrl = ref(null);
const existingFileName = ref('');
const totalDuration = ref(0);
const playerReady = ref(false);
const isPlaying = ref(false);
const currentTime = ref(0);
const loadError = ref('');
const fileInput = ref(null);

const audio = useAudioPlayer();

const getTotalDuration = () => totalDuration.value;

const {
    startSecond,
    endSecond,
    selectedDuration,
    clipDuration,
    validationError,
    setSelectedDuration,
    setStartPosition,
    validateSelection,
    resetSegment,
    loadSegment,
    normalizeSegment,
} = useMusicClip(getTotalDuration);

const syncPlayerSegment = () => {
    audio.setSegment(startSecond.value, endSecond.value);
};

const hasSelection = computed(() => playerReady.value && totalDuration.value > 0);

const displayTitle = computed(() => {
    if (selectedFile.value) {
        return selectedFile.value.name.replace(/\.mp3$/i, '');
    }

    return existingFileName.value || 'Arquivo MP3';
});

const buildSelection = () => ({
    source: 'mp3',
    title: displayTitle.value,
    file: selectedFile.value,
    existing_file_url: existingFileUrl.value,
    total_duration_seconds: totalDuration.value,
    duration_seconds: clipDuration.value,
    start_second: startSecond.value,
    end_second: endSecond.value,
});

const emitSelection = () => {
    if (!validateSelection(totalDuration.value)) {
        return;
    }

    const selection = buildSelection();
    emit('update:modelValue', selection);
    emit('selected', selection);
};

const initPlayer = async (source) => {
    playerReady.value = false;
    isPlaying.value = false;
    loadError.value = '';

    const segment = {
        start: startSecond.value,
        end: endSecond.value,
    };

    currentTime.value = segment.start;

    try {
        const element = await audio.init(source, {
            start: segment.start,
            end: segment.end,
            onReady: (audioElement) => {
                totalDuration.value = Math.floor(audioElement.duration) || 0;
                normalizeSegment(totalDuration.value);
                syncPlayerSegment();
                playerReady.value = true;
                currentTime.value = audio.getCurrentTime();
            },
            onTimeUpdate: (time) => {
                currentTime.value = time;
            },
            onPlayStateChange: (playing) => {
                isPlaying.value = playing;
            },
            onSegmentEnd: () => {
                isPlaying.value = false;
                currentTime.value = startSecond.value;
            },
        });

        totalDuration.value = Math.floor(element.duration) || totalDuration.value;
        syncPlayerSegment();
        await audio.seekTo(startSecond.value);
        currentTime.value = audio.getCurrentTime();
        emitSelection();

        try {
            await audio.playSegment();
            currentTime.value = audio.getCurrentTime();
        } catch {
            // Autoplay bloqueado pelo navegador.
        }
    } catch {
        loadError.value = 'Não foi possível carregar o arquivo MP3.';
        playerReady.value = false;
    }
};

const onFileChange = async (event) => {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    if (!file.name.toLowerCase().endsWith('.mp3')) {
        loadError.value = 'Envie um arquivo MP3.';
        return;
    }

    selectedFile.value = file;
    existingFileUrl.value = null;
    loadError.value = '';
    resetSegment();

    await initPlayer(file);
    normalizeSegment(totalDuration.value);
    syncPlayerSegment();
    currentTime.value = startSecond.value;
    emitSelection();
};

const openFilePicker = () => {
    fileInput.value?.click();
};

const onStartChange = async ({ start, play }) => {
    setStartPosition(start);
    audio.setSegment(startSecond.value, endSecond.value);
    await audio.seekTo(startSecond.value);
    currentTime.value = audio.getCurrentTime();

    if (play) {
        try {
            await audio.playSegment();
            currentTime.value = audio.getCurrentTime();
        } catch {
            // Autoplay bloqueado pelo navegador.
        }
    }

    emitSelection();
};

const onDurationChange = async (duration) => {
    setSelectedDuration(duration);
    syncPlayerSegment();
    await audio.seekTo(startSecond.value);
    currentTime.value = audio.getCurrentTime();
    emitSelection();
};

const togglePlayback = async () => {
    if (isPlaying.value) {
        audio.pausePreview();
        isPlaying.value = false;
        return;
    }

    await audio.playPreview();
    currentTime.value = audio.getCurrentTime();
};

onMounted(async () => {
    if (!props.modelValue?.existing_file_url && !props.modelValue?.file) {
        return;
    }

    if (props.modelValue.file instanceof File) {
        selectedFile.value = props.modelValue.file;
        loadSegment(props.modelValue);
        await initPlayer(props.modelValue.file);
        return;
    }

    if (props.modelValue.existing_file_url) {
        existingFileUrl.value = props.modelValue.existing_file_url;
        existingFileName.value = props.modelValue.title ?? 'Arquivo MP3';
        loadSegment(props.modelValue);
        await initPlayer(props.modelValue.existing_file_url);
    }
});

onUnmounted(() => {
    audio.destroy();
});

defineExpose({
    validateSelection: () => validateSelection(totalDuration.value),
    syncSelection: emitSelection,
});
</script>

<template>
    <div class="space-y-4" :class="{ 'opacity-60 pointer-events-none': disabled }">
        <div>
            <InputLabel for="music-mp3-upload" value="Enviar arquivo MP3" />
            <input id="music-mp3-upload" ref="fileInput" type="file" accept=".mp3,audio/mpeg" class="hidden"
                :disabled="disabled" @change="onFileChange" />
            <button type="button"
                class="mt-1 inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                :disabled="disabled" @click="openFilePicker">
                <i class="fa-solid fa-upload" />
                Escolher MP3
            </button>
            <p class="mt-1 text-xs text-gray-500">
                Use quando o vídeo oficial do YouTube não permitir reprodução incorporada.
            </p>
            <p v-if="loadError" class="mt-2 text-sm text-red-600">{{ loadError }}</p>
        </div>

        <div v-if="hasSelection" class="rounded-lg border border-gray-200 p-2 lg:p-4 bg-gray-50">
            <MusicClipEditor v-model:selected-duration="selectedDuration" :title="displayTitle" subtitle="Arquivo MP3"
                :total-duration="totalDuration" :clip-duration="clipDuration" :start-second="startSecond"
                :end-second="endSecond" :current-time="currentTime" :player-ready="playerReady" :is-playing="isPlaying"
                :validation-error="validationError" :disabled="disabled" @toggle-playback="togglePlayback"
                @duration-change="onDurationChange" @start-change="onStartChange">
                <template #player>
                    <div class="rounded-lg bg-gray-900 px-4 py-6 text-center text-white">
                        <i class="fa-solid fa-file-audio text-3xl text-indigo-300" />
                        <p class="mt-2 text-sm">{{ displayTitle }}.mp3</p>
                    </div>
                </template>
            </MusicClipEditor>
        </div>
    </div>
</template>

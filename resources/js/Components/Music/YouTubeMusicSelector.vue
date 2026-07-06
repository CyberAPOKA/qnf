<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import MusicClipEditor from '@/Components/Music/MusicClipEditor.vue';
import { formatSeconds, useYouTubePlayer } from '@/composables/useYouTubePlayer';
import { filterEmbeddableVideos } from '@/composables/useYouTubeEmbedValidator';
import { useMusicClip } from '@/composables/useMusicClip';
import { extractYouTubeVideoId } from '@/utils/youtube';

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

const query = ref('');
const results = ref([]);
const selectedVideo = ref(null);
const isSearching = ref(false);
const isValidating = ref(false);
const isLoadingVideo = ref(false);
const playerReady = ref(false);
const isPlaying = ref(false);
const currentTime = ref(0);
const playerError = ref('');
const searchError = ref('');

const playerElementId = `youtube-music-player-${Math.random().toString(36).slice(2)}`;
const youtube = useYouTubePlayer();

let searchTimeout = null;
let timeUpdateInterval = null;
let suppressSearch = false;

const getTotalDuration = () => selectedVideo.value?.duration_seconds ?? 0;

const {
    startSecond,
    endSecond,
    selectedDuration,
    clipDuration,
    validationError,
    setSelectedDuration,
    setStartPosition,
    validateSelection: validateClip,
    resetSegment,
    loadSegment,
    normalizeSegment,
} = useMusicClip(getTotalDuration);

const setQueryWithoutSearch = (value) => {
    suppressSearch = true;
    query.value = value;
};

const syncPlayerSegment = () => {
    youtube.setSegment(startSecond.value, endSecond.value);
};

const hasSelection = computed(() => Boolean(selectedVideo.value));

const buildSelection = () => {
    if (!selectedVideo.value) {
        return null;
    }

    return {
        source: 'youtube',
        youtube_video_id: selectedVideo.value.id,
        title: selectedVideo.value.title,
        channel: selectedVideo.value.channel,
        thumbnail: selectedVideo.value.thumbnail,
        duration_seconds: clipDuration.value,
        start_second: startSecond.value,
        end_second: endSecond.value,
        watch_url: selectedVideo.value.watch_url,
    };
};

const validateSelection = () => validateClip(getTotalDuration());

const emitSelection = () => {
    if (!validateSelection()) {
        return;
    }

    const selection = buildSelection();
    emit('update:modelValue', selection);
    emit('selected', selection);
};

const clearTimeUpdateInterval = () => {
    if (timeUpdateInterval) {
        clearInterval(timeUpdateInterval);
        timeUpdateInterval = null;
    }
};

const startTimeUpdateInterval = () => {
    clearTimeUpdateInterval();
    timeUpdateInterval = setInterval(() => {
        currentTime.value = youtube.getCurrentTime();
    }, 200);
};

const initPlayer = async (video) => {
    playerReady.value = false;
    isPlaying.value = false;
    playerError.value = '';
    currentTime.value = startSecond.value;

    await nextTick();

    await youtube.init(playerElementId, video.id, {
        start: startSecond.value,
        end: endSecond.value,
        onReady: (event) => {
            const playerDuration = Math.floor(event.target.getDuration()) || video.duration_seconds;
            selectedVideo.value = { ...selectedVideo.value, duration_seconds: playerDuration };
            normalizeSegment(playerDuration);
            syncPlayerSegment();
            youtube.seekTo(startSecond.value);
            playerReady.value = true;
            currentTime.value = startSecond.value;
        },
        onStateChange: (event) => {
            isPlaying.value = event.data === window.YT.PlayerState.PLAYING;

            if (isPlaying.value) {
                startTimeUpdateInterval();
            } else {
                clearTimeUpdateInterval();
                currentTime.value = youtube.getCurrentTime();
            }
        },
        onSegmentEnd: () => {
            isPlaying.value = false;
            currentTime.value = startSecond.value;
        },
        onError: (message) => {
            playerError.value = message;
            playerReady.value = false;
            isPlaying.value = false;
            clearTimeUpdateInterval();
            discardUnembeddableVideo(message);
        },
    });
};

const discardUnembeddableVideo = (message) => {
    if (!selectedVideo.value) {
        return;
    }

    const rejectedId = selectedVideo.value.id;
    results.value = results.value.filter((result) => result.id !== rejectedId);
    selectedVideo.value = null;
    playerError.value = message;
    searchError.value = 'Este vídeo não permite reprodução incorporada. Escolha outro resultado ou envie um MP3.';
    youtube.destroy();
};

const selectResult = async (result) => {
    if (props.disabled) {
        return;
    }

    isLoadingVideo.value = true;
    playerError.value = '';
    searchError.value = '';

    results.value = [];
    setQueryWithoutSearch(result.title);

    try {
        await applyVideoSelection(result, true);
    } finally {
        isLoadingVideo.value = false;
    }
};

const applyVideoSelection = async (video, shouldResetSegment = false) => {
    selectedVideo.value = video;

    if (shouldResetSegment) {
        resetSegment();
    }

    await initPlayer(video);
    emitSelection();
};

const loadVideoFromUrl = async (videoId) => {
    isSearching.value = true;
    isValidating.value = false;
    isLoadingVideo.value = true;
    searchError.value = '';
    playerError.value = '';
    results.value = [];

    try {
        const { data } = await axios.get(route('api.youtube.show', videoId));

        isSearching.value = false;
        isValidating.value = true;

        const validated = await filterEmbeddableVideos([data.video]);

        if (!validated.length) {
            searchError.value = 'Este vídeo não permite reprodução incorporada. Escolha outro link, busque por nome ou envie um MP3.';
            selectedVideo.value = null;
            youtube.destroy();
            return;
        }

        await applyVideoSelection(validated[0], true);
        setQueryWithoutSearch(validated[0].title);
    } catch (error) {
        selectedVideo.value = null;
        youtube.destroy();

        const status = error.response?.status;
        const message = error.response?.data?.message;

        if (status === 422) {
            searchError.value = message ?? 'Este vídeo não permite reprodução incorporada. Escolha outro link ou envie um MP3.';
        } else if (status === 404) {
            searchError.value = message ?? 'Vídeo não encontrado. Verifique a URL.';
        } else if (status === 503) {
            searchError.value = message ?? 'Limite da API do YouTube atingido. Tente novamente mais tarde.';
        } else {
            searchError.value = message ?? 'Não foi possível carregar o vídeo desta URL.';
        }
    } finally {
        isSearching.value = false;
        isValidating.value = false;
        isLoadingVideo.value = false;
    }
};

const searchMusic = async (term) => {
    const trimmed = term.trim();

    if (trimmed.length < 2) {
        results.value = [];
        searchError.value = '';
        return;
    }

    const videoId = extractYouTubeVideoId(trimmed);

    if (videoId) {
        await loadVideoFromUrl(videoId);
        return;
    }

    isSearching.value = true;
    isValidating.value = false;
    searchError.value = '';
    results.value = [];

    try {
        const { data } = await axios.get(route('api.youtube.search'), { params: { q: term } });
        const candidates = data.results ?? [];

        if (!candidates.length) {
            searchError.value = 'Nenhuma música incorporável encontrada. Tente outro termo ou envie um MP3.';
            return;
        }

        isSearching.value = false;
        isValidating.value = true;

        results.value = await filterEmbeddableVideos(candidates);

        if (!results.value.length) {
            searchError.value = 'Nenhuma música incorporável encontrada. Tente outro termo ou envie um MP3.';
        }
    } catch (error) {
        results.value = [];
        searchError.value = error.response?.data?.message ?? 'Erro ao buscar músicas.';
    } finally {
        isSearching.value = false;
        isValidating.value = false;
    }
};

const onStartChange = ({ start, play }) => {
    setStartPosition(start);
    youtube.setSegment(startSecond.value, endSecond.value);
    youtube.seekTo(startSecond.value);
    currentTime.value = startSecond.value;

    if (play) {
        youtube.playPreview();
    }

    emitSelection();
};

const onDurationChange = (duration) => {
    setSelectedDuration(duration);
    syncPlayerSegment();
    youtube.seekTo(startSecond.value);
    currentTime.value = startSecond.value;
    emitSelection();
};

const togglePlayback = () => {
    if (isPlaying.value) {
        youtube.pausePreview();
        isPlaying.value = false;
        return;
    }

    youtube.playPreview();
};

watch(query, (value) => {
    if (suppressSearch) {
        suppressSearch = false;
        return;
    }

    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => searchMusic(value), 500);
});

watch(
    () => props.modelValue,
    (value) => {
        if (!value?.youtube_video_id || selectedVideo.value?.id === value.youtube_video_id) {
            return;
        }

        loadSegment(value);
    },
    { deep: true },
);

onMounted(async () => {
    if (!props.modelValue?.youtube_video_id) {
        return;
    }

    isLoadingVideo.value = true;

    try {
        const { data } = await axios.get(route('api.youtube.show', props.modelValue.youtube_video_id));
        loadSegment(props.modelValue);
        await applyVideoSelection(data.video, false);
        setQueryWithoutSearch(data.video.title);
    } catch {
        loadSegment(props.modelValue);
        await applyVideoSelection({
            id: props.modelValue.youtube_video_id,
            title: props.modelValue.title ?? '',
            channel: props.modelValue.channel ?? '',
            thumbnail: props.modelValue.thumbnail ?? '',
            duration_seconds: props.modelValue.end_second ?? 30,
            watch_url: props.modelValue.watch_url ?? '',
            embed_url: '',
        }, false);
        setQueryWithoutSearch(props.modelValue.title ?? '');
    } finally {
        isLoadingVideo.value = false;
    }
});

onUnmounted(() => {
    clearTimeout(searchTimeout);
    clearTimeUpdateInterval();
    youtube.destroy();
});

defineExpose({ validateSelection, syncSelection: emitSelection });
</script>

<template>
    <div class="space-y-6" :class="{ 'opacity-60 pointer-events-none': disabled }">
        <div>
            <InputLabel for="youtube-music-search" value="Buscar música no YouTube ou colar URL" />
            <TextInput id="youtube-music-search" v-model="query" type="search" class="mt-1 block w-full"
                placeholder="Nome da música, artista ou https://www.youtube.com/watch?v=..." autocomplete="off"
                :disabled="disabled" />
            <p class="mt-1 text-xs text-gray-500">
                Aceita busca por texto ou URL do YouTube (watch, youtu.be, embed, shorts).
            </p>
            <p v-if="isSearching" class="mt-2 text-sm text-gray-500">Buscando...</p>
            <p v-else-if="isValidating" class="mt-2 text-sm text-gray-500">Verificando quais vídeos permitem
                reprodução...</p>
            <p v-else-if="searchError" class="mt-2 text-sm text-red-600">{{ searchError }}</p>
        </div>

        <ul v-if="results.length"
            class="rounded-lg border border-gray-200 divide-y divide-gray-200 overflow-hidden max-h-72 overflow-y-auto">
            <li v-for="result in results" :key="result.id"
                class="flex items-center gap-3 p-3 hover:bg-gray-50 cursor-pointer transition"
                @click="selectResult(result)">
                <img :src="result.thumbnail" :alt="result.title" class="w-16 h-12 object-cover rounded shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ result.title }}</p>
                    <p class="text-xs text-gray-500 truncate">{{ result.channel }}</p>
                </div>
                <span class="text-xs text-gray-500 font-mono shrink-0">
                    {{ formatSeconds(result.duration_seconds) }}
                </span>
            </li>
        </ul>

        <div v-if="isLoadingVideo" class="text-sm text-gray-500">
            Carregando vídeo...
        </div>

        <div v-if="hasSelection" class="rounded-lg border border-gray-200 p-2 lg:p-4 bg-gray-50">
            <MusicClipEditor v-model:selected-duration="selectedDuration" :title="selectedVideo.title"
                :subtitle="selectedVideo.channel" :total-duration="selectedVideo.duration_seconds"
                :clip-duration="clipDuration" :start-second="startSecond" :end-second="endSecond"
                :current-time="currentTime" :player-ready="playerReady" :is-playing="isPlaying"
                :validation-error="validationError" :disabled="disabled" @toggle-playback="togglePlayback"
                @duration-change="onDurationChange" @start-change="onStartChange">
                <template #player>
                    <div class="aspect-video w-full max-w-md rounded-lg overflow-hidden bg-black relative">
                        <div :id="playerElementId" class="w-full h-full" />
                        <div v-if="!playerReady && !playerError"
                            class="absolute inset-0 flex items-center justify-center bg-black/70 text-white text-sm">
                            Carregando player...
                        </div>
                    </div>
                    <p v-if="playerError" class="mt-2 text-sm text-red-600">
                        {{ playerError }}
                    </p>
                </template>
            </MusicClipEditor>
        </div>
    </div>
</template>

<style scoped>
:deep(iframe) {
    width: 100%;
    height: 100%;
}
</style>

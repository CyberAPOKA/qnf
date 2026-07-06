<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useAudioPlayer } from '@/composables/useAudioPlayer';
import { useYouTubePlayer } from '@/composables/useYouTubePlayer';

const props = defineProps({
    teams: { type: Array, default: () => [] },
});

const DEFAULT_MUSIC_SRC = '/sounds/WhatsUpDanger.mp3';
const YOUTUBE_PLAYER_ID = 'week-team-yt-player';

const currentIndex = ref(0);
const isPlaying = ref(false);
const glitching = ref(false);
const defaultAudio = ref(null);
const activeSource = ref('default');

const youtubePlayer = useYouTubePlayer();
const audioPlayer = useAudioPlayer();

let glitchInterval = null;
let glitchDelay = null;
let glitchRunning = false;
let switchingTeam = false;

const hasTeams = computed(() => props.teams.length > 0);
const currentTeam = computed(() => props.teams[currentIndex.value] ?? null);
const currentImage = computed(() => currentTeam.value?.image ?? null);
const multipleTeams = computed(() => props.teams.length > 1);

const flash = (duration = 100) => new Promise((resolve) => {
    glitching.value = true;
    setTimeout(() => {
        glitching.value = false;
        setTimeout(resolve, 80);
    }, duration);
});

const runGlitchCycle = async () => {
    await flash(100);
    await flash(100);
    await flash(100);
    await new Promise((r) => setTimeout(r, 1000));
    await flash(100);
    await flash(100);
};

const startGlitchLoop = () => {
    if (glitchRunning) return;
    glitchRunning = true;

    const loop = async () => {
        if (!glitchRunning) return;
        await runGlitchCycle();
        if (!glitchRunning) return;
        glitchInterval = setTimeout(loop, 6000);
    };

    loop();
};

const stopGlitchLoop = () => {
    glitchRunning = false;
    glitching.value = false;

    if (glitchInterval) {
        clearTimeout(glitchInterval);
        glitchInterval = null;
    }

    if (glitchDelay) {
        clearTimeout(glitchDelay);
        glitchDelay = null;
    }
};

const scheduleGlitch = () => {
    stopGlitchLoop();
    glitchDelay = setTimeout(() => startGlitchLoop(), 21000);
};

const stopDefaultAudio = () => {
    if (!defaultAudio.value) return;

    defaultAudio.value.pause();
    defaultAudio.value.onended = null;
};

const destroyActivePlayer = () => {
    stopDefaultAudio();
    youtubePlayer.destroy();
    audioPlayer.destroy();
    activeSource.value = 'default';
};

const handleMusicEnded = () => {
    if (!isPlaying.value || switchingTeam) return;

    if (!multipleTeams.value) {
        playCurrentMusic();
        return;
    }

    switchingTeam = true;
    currentIndex.value = (currentIndex.value + 1) % props.teams.length;
    playCurrentMusic().finally(() => {
        switchingTeam = false;
    });
};

const playDefaultMusic = async () => {
    if (!defaultAudio.value) return;

    activeSource.value = 'default';
    defaultAudio.value.currentTime = 0;
    defaultAudio.value.onended = () => handleMusicEnded();

    await defaultAudio.value.play();
};

const parseSegmentBounds = (music) => ({
    start: Number(music.start_seconds) || 0,
    end: Number(music.end_seconds) || 30,
});

const playCurrentMusic = async () => {
    const music = currentTeam.value?.music;
    destroyActivePlayer();

    if (!music || music.source === 'default') {
        activeSource.value = 'default';
        await playDefaultMusic();
        return;
    }

    const { start, end } = parseSegmentBounds(music);

    if (music.source === 'youtube' && music.youtube_id) {
        activeSource.value = 'youtube';

        await youtubePlayer.init(YOUTUBE_PLAYER_ID, music.youtube_id, {
            start,
            end,
            onSegmentEnd: handleMusicEnded,
            onStateChange: (event) => {
                if (event.data === window.YT?.PlayerState?.PLAYING) {
                    scheduleGlitch();
                }
            },
        });

        youtubePlayer.playSegment();
        return;
    }

    if (music.source === 'mp3' && music.file_url) {
        activeSource.value = 'mp3';

        await audioPlayer.init(music.file_url, {
            start,
            end,
            onSegmentEnd: handleMusicEnded,
            onPlayStateChange: (playing) => {
                if (playing) {
                    scheduleGlitch();
                }
            },
        });

        await audioPlayer.playSegment();
    }
};

const pauseCurrentMusic = () => {
    if (activeSource.value === 'youtube') {
        youtubePlayer.pausePreview();
    } else if (activeSource.value === 'mp3') {
        audioPlayer.pausePreview();
    } else {
        stopDefaultAudio();
    }
};

const toggleMusic = async () => {
    if (!hasTeams.value) return;

    if (isPlaying.value) {
        pauseCurrentMusic();
        isPlaying.value = false;
        stopGlitchLoop();
        return;
    }

    try {
        await playCurrentMusic();
        isPlaying.value = true;
        scheduleGlitch();
    } catch {
        isPlaying.value = false;
    }
};

const tryAutoplay = () => {
    if (isPlaying.value) return;

    playCurrentMusic()
        .then(() => {
            isPlaying.value = true;
            scheduleGlitch();
        })
        .catch(() => {
            const playOnInteraction = () => {
                if (!isPlaying.value) {
                    playCurrentMusic()
                        .then(() => {
                            isPlaying.value = true;
                            scheduleGlitch();
                        })
                        .catch(() => {});
                }

                document.removeEventListener('click', playOnInteraction);
                document.removeEventListener('touchstart', playOnInteraction);
            };

            document.addEventListener('click', playOnInteraction, { once: true });
            document.addEventListener('touchstart', playOnInteraction, { once: true });
        });
};

const switchToTeam = async (index) => {
    if (index === currentIndex.value) return;

    currentIndex.value = index;

    if (isPlaying.value) {
        try {
            await playCurrentMusic();
            scheduleGlitch();
        } catch {
            isPlaying.value = false;
        }
    }
};

const downloadAll = () => {
    props.teams.forEach((team, i) => {
        const a = document.createElement('a');
        a.href = team.image;
        a.download = `time-da-semana-${i + 1}.png`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    });
};

watch(
    () => props.teams,
    () => {
        currentIndex.value = 0;
        destroyActivePlayer();
        isPlaying.value = false;
        stopGlitchLoop();

        if (props.teams.length) {
            tryAutoplay();
        }
    },
    { deep: true },
);

onMounted(() => {
    if (props.teams.length) {
        tryAutoplay();
    }
});

onUnmounted(() => {
    stopGlitchLoop();
    destroyActivePlayer();
});
</script>

<template>
    <div v-if="hasTeams" class="rounded-xl bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 p-3 shadow-lg">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-bold text-yellow-400">
                <i class="fa-solid fa-star mr-2"></i>
                <span v-if="multipleTeams">Times da semana</span>
                <span v-else>Time da Semana</span>
            </h3>
            <div class="flex items-center gap-2">
                <button
                    @click="downloadAll"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold bg-gray-700 text-gray-300 hover:bg-gray-600 transition"
                >
                    <i class="fa-solid fa-download"></i>
                    Baixar
                </button>
                <button
                    @click="toggleMusic"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition"
                    :class="isPlaying
                        ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                >
                    <i :class="isPlaying ? 'fa-solid fa-pause' : 'fa-solid fa-play'"></i>
                    {{ isPlaying ? 'Pausar' : 'Tocar' }}
                </button>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-lg">
            <transition name="fade">
                <img
                    :key="currentIndex"
                    :src="currentImage"
                    alt="Time da Semana"
                    class="w-full rounded-lg absolute inset-0"
                    :class="{ 'glitch-flash': glitching }"
                />
            </transition>
            <img :src="teams[0]?.image" alt="" class="w-full rounded-lg invisible" aria-hidden="true" />

            <div v-if="multipleTeams" class="flex justify-center gap-2 mt-2">
                <button
                    v-for="(_, i) in teams"
                    :key="i"
                    @click="switchToTeam(i)"
                    class="h-2.5 w-2.5 rounded-full transition"
                    :class="i === currentIndex ? 'bg-yellow-400' : 'bg-gray-600'"
                />
            </div>
        </div>

        <div :id="YOUTUBE_PLAYER_ID" class="fixed w-px h-px opacity-0 pointer-events-none overflow-hidden" aria-hidden="true" />

        <audio ref="defaultAudio" preload="none">
            <source :src="DEFAULT_MUSIC_SRC" type="audio/mpeg" />
        </audio>
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 1.2s ease-in-out;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.fade-leave-active {
    position: absolute;
    inset: 0;
}

.glitch-flash {
    filter: invert(1) hue-rotate(180deg) saturate(2.5) brightness(1.3);
    transition: filter 0.05s;
}
</style>

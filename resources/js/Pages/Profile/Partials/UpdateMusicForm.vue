<script setup>
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import Mp3MusicSelector from '@/Components/Music/Mp3MusicSelector.vue';
import YouTubeMusicSelector from '@/Components/Music/YouTubeMusicSelector.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const user = usePage().props.auth.user;

const resolveInitialSource = () => {
    if (user.music_source) {
        return user.music_source;
    }

    if (user.music_file_path || user.music_file_url) {
        return 'mp3';
    }

    if (user.music_youtube_id) {
        return 'youtube';
    }

    return 'youtube';
};

const musicSource = ref(resolveInitialSource());
const youtubeSelectorRef = ref(null);
const mp3SelectorRef = ref(null);
const musicSelection = ref(buildInitialSelection());

function buildInitialSelection() {
    if ((user.music_source === 'mp3' || user.music_file_url) && user.music_file_url) {
        return {
            source: 'mp3',
            title: user.music_title ?? '',
            existing_file_url: user.music_file_url,
            duration_seconds: user.music_duration_seconds ?? 30,
            start_second: user.music_start_seconds ?? 0,
            end_second: user.music_end_seconds ?? 30,
        };
    }

    if (user.music_youtube_id) {
        return {
            source: 'youtube',
            youtube_video_id: user.music_youtube_id,
            title: user.music_title ?? '',
            channel: user.music_channel ?? '',
            thumbnail: user.music_thumbnail_url ?? '',
            duration_seconds: user.music_duration_seconds ?? 30,
            start_second: user.music_start_seconds ?? 0,
            end_second: user.music_end_seconds ?? 30,
            watch_url: user.music_watch_url ?? '',
        };
    }

    return null;
}

const form = useForm({
    music_source: musicSource.value,
    music_youtube_id: user.music_youtube_id ?? '',
    music_title: user.music_title ?? '',
    music_channel: user.music_channel ?? '',
    music_thumbnail_url: user.music_thumbnail_url ?? '',
    music_start_seconds: user.music_start_seconds ?? 0,
    music_end_seconds: user.music_end_seconds ?? 30,
    music_duration_seconds: user.music_duration_seconds ?? 30,
    music_watch_url: user.music_watch_url ?? '',
    music_file: null,
});

const getActiveSelector = () => (
    musicSource.value === 'mp3' ? mp3SelectorRef.value : youtubeSelectorRef.value
);

const syncFormFromSelection = (selection) => {
    if (!selection) {
        return;
    }

    form.music_source = selection.source ?? musicSource.value;
    form.music_title = selection.title;
    form.music_start_seconds = selection.start_second;
    form.music_end_seconds = selection.end_second;
    form.music_duration_seconds = selection.end_second - selection.start_second;

    if (selection.source === 'mp3') {
        form.music_youtube_id = '';
        form.music_channel = '';
        form.music_thumbnail_url = '';
        form.music_watch_url = '';
        form.music_file = selection.file ?? null;
        return;
    }

    form.music_youtube_id = selection.youtube_video_id;
    form.music_channel = selection.channel ?? '';
    form.music_thumbnail_url = selection.thumbnail ?? '';
    form.music_watch_url = selection.watch_url ?? '';
    form.music_file = null;
};

const onMusicSelected = (selection) => {
    musicSelection.value = selection;
    syncFormFromSelection(selection);
};

const onSourceChange = (source) => {
    if (musicSource.value === source) {
        return;
    }

    musicSource.value = source;
    form.music_source = source;

    if (source === 'mp3' && (user.music_file_url || user.music_source === 'mp3')) {
        musicSelection.value = {
            source: 'mp3',
            title: user.music_title ?? '',
            existing_file_url: user.music_file_url,
            duration_seconds: user.music_duration_seconds ?? 30,
            start_second: user.music_start_seconds ?? 0,
            end_second: user.music_end_seconds ?? 30,
        };
        syncFormFromSelection(musicSelection.value);
        return;
    }

    if (source === 'youtube' && user.music_youtube_id) {
        musicSelection.value = {
            source: 'youtube',
            youtube_video_id: user.music_youtube_id,
            title: user.music_title ?? '',
            channel: user.music_channel ?? '',
            thumbnail: user.music_thumbnail_url ?? '',
            duration_seconds: user.music_duration_seconds ?? 30,
            start_second: user.music_start_seconds ?? 0,
            end_second: user.music_end_seconds ?? 30,
            watch_url: user.music_watch_url ?? '',
        };
        syncFormFromSelection(musicSelection.value);
        return;
    }

    musicSelection.value = null;
};

const submit = () => {
    const selector = getActiveSelector();

    if (!selector) {
        return;
    }

    selector.syncSelection();

    if (!selector.validateSelection()) {
        return;
    }

    syncFormFromSelection(musicSelection.value);

    form.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('profile.update-music'), {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="submit">
        <template #title>
            Música
        </template>

        <template #description>
            Escolha a música que tocará no seu perfil. Busque no YouTube ou envie um MP3, ajuste o trecho e clique em Salvar.
        </template>

        <template #form>
            <div class="col-span-6 space-y-4">
                <div class="inline-flex rounded-lg border border-gray-200 p-1 bg-white">
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-semibold transition"
                        :class="musicSource === 'youtube'
                            ? 'bg-indigo-600 text-white'
                            : 'text-gray-600 hover:text-gray-900'"
                        @click="onSourceChange('youtube')"
                    >
                        <i class="fa-brands fa-youtube mr-1.5" />
                        YouTube
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-semibold transition"
                        :class="musicSource === 'mp3'
                            ? 'bg-indigo-600 text-white'
                            : 'text-gray-600 hover:text-gray-900'"
                        @click="onSourceChange('mp3')"
                    >
                        <i class="fa-solid fa-file-audio mr-1.5" />
                        Enviar MP3
                    </button>
                </div>

                <YouTubeMusicSelector
                    v-if="musicSource === 'youtube'"
                    ref="youtubeSelectorRef"
                    v-model="musicSelection"
                    @selected="onMusicSelected"
                />

                <Mp3MusicSelector
                    v-else
                    ref="mp3SelectorRef"
                    v-model="musicSelection"
                    @selected="onMusicSelected"
                />

                <InputError :message="form.errors.music_youtube_id" class="mt-2" />
                <InputError :message="form.errors.music_file" class="mt-2" />
                <InputError :message="form.errors.music_duration_seconds" class="mt-2" />
                <InputError :message="form.errors.music_end_seconds" class="mt-2" />
            </div>
        </template>

        <template #actions>
            <ActionMessage :on="form.recentlySuccessful" class="me-3">
                Salvo.
            </ActionMessage>

            <PrimaryButton
                :class="{ 'opacity-25': form.processing || !musicSelection }"
                :disabled="form.processing || !musicSelection"
            >
                Salvar
            </PrimaryButton>
        </template>
    </FormSection>
</template>

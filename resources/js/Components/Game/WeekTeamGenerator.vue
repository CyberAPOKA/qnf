<script setup>
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    gameId: {
        type: Number,
        default: null,
    },
});

const loading = ref(false);
const result = ref(null);
const images = ref([]);

const generate = async () => {
    if (!props.gameId || loading.value) return;
    loading.value = true;
    result.value = null;
    try {
        const { data } = await axios.post(route('api.games.regenerate-week-team', props.gameId));
        images.value = (data.teams || []).map((team) => team.image);
        result.value = 'Time da semana gerado!';
    } catch (e) {
        result.value = e.response?.data?.error || 'Erro ao gerar time da semana';
    } finally {
        loading.value = false;
        setTimeout(() => { result.value = null; }, 4000);
    }
};
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <button type="button" :disabled="loading"
            class="w-full rounded-md bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-600 disabled:opacity-50"
            @click="generate">
            <i class="fa-solid fa-image mr-1.5" aria-hidden="true" />
            {{ loading ? 'Gerando...' : 'GERAR TIME DA SEMANA' }}
        </button>

        <p v-if="result" class="text-sm font-medium text-gray-700">{{ result }}</p>

        <div v-if="images.length" class="w-full space-y-2">
            <img v-for="(src, i) in images" :key="i" :src="src" alt="Time da Semana" class="w-full rounded-lg shadow">
        </div>
    </div>
</template>

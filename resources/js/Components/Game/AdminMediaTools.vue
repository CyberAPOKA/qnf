<script setup>
import { ref } from 'vue';
import axios from 'axios';
import FuturisticButton from '@/Components/FuturisticButton.vue';

const whatsappLabel = ref('Teste WhatsApp');
const whatsappTesting = ref(false);

const sendWhatsAppTest = async () => {
    if (whatsappTesting.value) return;
    whatsappTesting.value = true;
    whatsappLabel.value = 'Enviando...';
    try {
        await axios.post(route('api.whatsapp.send-test'));
        whatsappLabel.value = 'Enviado!';
    } catch (e) {
        whatsappLabel.value = e.response?.data?.error || 'Erro';
    } finally {
        setTimeout(() => {
            whatsappLabel.value = 'Teste WhatsApp';
            whatsappTesting.value = false;
        }, 3000);
    }
};

const allWeekTeamsLoading = ref(false);
const allWeekTeamsResult = ref(null);

const regenerateAllWeekTeams = async () => {
    if (allWeekTeamsLoading.value) return;
    if (!confirm('Gerar imagens de Time da Semana para TODAS as rodadas finalizadas? Pode demorar.')) return;
    allWeekTeamsLoading.value = true;
    allWeekTeamsResult.value = null;
    try {
        const { data } = await axios.post(route('api.games.regenerate-all-week-teams'));
        allWeekTeamsResult.value = data.message;
    } catch (e) {
        allWeekTeamsResult.value = e.response?.data?.error || 'Erro ao gerar times da semana';
    } finally {
        allWeekTeamsLoading.value = false;
        setTimeout(() => { allWeekTeamsResult.value = null; }, 6000);
    }
};

const generators = ref([
    { key: 'captains', label: 'Capitães', routeName: 'api.captains.generate', imgClass: 'w-full', loading: false, image: null },
    { key: 'lineups', label: 'Escalações', routeName: 'api.lineups.generate', imgClass: 'w-full', loading: false, image: null },
    { key: 'ranking', label: 'Ranking', routeName: 'api.ranking.generate', imgClass: 'w-fit max-h-fit', loading: false, image: null },
]);

const generateImage = async (generator) => {
    if (generator.loading) return;
    generator.loading = true;
    try {
        const { data } = await axios.post(route(generator.routeName));
        generator.image = `${data.image}?t=${Date.now()}`;
    } catch (e) {
        console.error(`Failed to generate ${generator.key} image`, e);
    } finally {
        generator.loading = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-center">
            <button type="button" :disabled="whatsappTesting"
                class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-700 disabled:opacity-50"
                @click="sendWhatsAppTest">
                <i class="fa-brands fa-whatsapp mr-1.5" aria-hidden="true" />
                {{ whatsappLabel }}
            </button>
        </div>

        <div class="flex flex-col items-center gap-2">
            <button type="button" :disabled="allWeekTeamsLoading"
                class="w-full rounded-md bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-purple-700 disabled:opacity-50"
                @click="regenerateAllWeekTeams">
                <i class="fa-solid fa-images mr-1.5" aria-hidden="true" />
                {{ allWeekTeamsLoading ? 'Gerando...' : 'Gerar Times da Semana (todas as rodadas)' }}
            </button>
            <p v-if="allWeekTeamsResult" class="text-sm font-medium text-gray-700">{{ allWeekTeamsResult }}</p>
        </div>

        <div v-for="generator in generators" :key="generator.key" class="space-y-2">
            <FuturisticButton :label="generator.loading ? 'Gerando...' : `Gerar ${generator.label}`"
                @click="generateImage(generator)" />
            <img v-if="generator.image" :src="generator.image" :alt="generator.label" class="rounded-lg shadow"
                :class="generator.imgClass">
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const MAX_LENGTH = 500;

const message = ref('');
const voice = ref('lula');
const sending = ref(false);
const error = ref('');
const success = ref('');

const characterCount = computed(() => message.value.length);
const canSend = computed(() => {
    const text = message.value.trim();

    return text.length > 0 && text.length <= MAX_LENGTH && !sending.value;
});

const sendVoiceMessage = async () => {
    if (!canSend.value) return;

    sending.value = true;
    error.value = '';
    success.value = '';

    try {
        await axios.post(route('api.whatsapp.send-voice'), {
            message: message.value.trim(),
            voice: voice.value,
        }, { timeout: 120000 });

        success.value = 'Áudio enviado para o grupo.';
        message.value = '';
    } catch (e) {
        const validation = e.response?.data?.errors;
        error.value = validation?.message?.[0]
            || validation?.voice?.[0]
            || e.response?.data?.error
            || 'Erro ao enviar áudio.';
    } finally {
        sending.value = false;
        if (success.value) {
            setTimeout(() => { success.value = ''; }, 4000);
        }
    }
};
</script>

<template>
    <div class="rounded-xl bg-white p-2 shadow lg:p-4">
        <h3 class="text-base font-semibold text-gray-900">Mensagem de voz</h3>
        <p class="mt-1 text-sm text-gray-500">Gera o áudio e envia no grupo do WhatsApp.</p>

        <div class="mt-3 space-y-3">
            <div>
                <InputLabel value="Mensagem" required />
                <textarea
                    v-model="message"
                    :maxlength="MAX_LENGTH"
                    rows="4"
                    class="mt-1 h-28 w-full rounded-lg border-gray-300 text-sm"
                    placeholder="Digite a mensagem que será narrada..."
                />
                <div class="mt-1 flex items-center justify-between">
                    <InputError :message="error" />
                    <p class="ms-auto text-xs text-gray-500">{{ characterCount }}/{{ MAX_LENGTH }}</p>
                </div>
            </div>

            <div>
                <InputLabel value="Voz" required />
                <select v-model="voice" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    <option value="lula">Lula</option>
                    <option value="bolsonaro">Bolsonaro</option>
                </select>
            </div>

            <p v-if="success" class="text-sm font-medium text-green-600">{{ success }}</p>

            <PrimaryButton
                type="button"
                class="w-full justify-center py-3 text-base"
                :disabled="!canSend"
                @click="sendVoiceMessage"
            >
                <i class="fa-solid fa-paper-plane mr-1.5" aria-hidden="true" />
                {{ sending ? 'Gerando e enviando...' : 'Enviar áudio' }}
            </PrimaryButton>
        </div>
    </div>
</template>

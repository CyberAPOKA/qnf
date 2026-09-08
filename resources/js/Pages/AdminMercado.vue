<script setup>
import { computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    schedule: {
        type: Object,
        required: true,
    },
    has_pending: {
        type: Boolean,
        default: false,
    },
    current_game: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    creates_at: props.schedule.creates_at,
    opens_at: props.schedule.opens_at,
    starts_at: props.schedule.starts_at,
});

watch(() => props.schedule, (schedule) => {
    form.creates_at = schedule.creates_at;
    form.opens_at = schedule.opens_at;
    form.starts_at = schedule.starts_at;
}, { deep: true });

const currentForm = useForm({
    opens_at: props.current_game?.opens_at ?? '',
    starts_at: props.current_game?.starts_at ?? '',
});

watch(() => props.current_game, (game) => {
    currentForm.opens_at = game?.opens_at ?? '';
    currentForm.starts_at = game?.starts_at ?? '';
}, { deep: true });

const saveSchedule = () => {
    form.post(route('admin.mercado.store'), { preserveScroll: true });
};

const createNow = () => {
    form.post(route('admin.mercado.store'), {
        preserveScroll: true,
        onSuccess: () => {
            router.post(route('admin.mercado.create-game'), {}, { preserveScroll: true });
        },
    });
};

const saveCurrentGame = () => {
    if (!props.current_game) return;

    currentForm.post(route('admin.mercado.update-game', props.current_game.id), {
        preserveScroll: true,
    });
};

const weekdayLabel = (value) => {
    if (!value) {
        return '';
    }

    const [datePart, timePart] = String(value).split('T');
    if (!datePart || !timePart) {
        return '';
    }

    const [year, month, day] = datePart.split('-').map(Number);
    const [hour, minute] = timePart.split(':').map(Number);
    const date = new Date(year, month - 1, day, hour, minute || 0);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const weekday = new Intl.DateTimeFormat('pt-BR', { weekday: 'long' }).format(date);
    const monthName = new Intl.DateTimeFormat('pt-BR', { month: 'long' }).format(date);
    const weekdayTitle = weekday.charAt(0).toUpperCase() + weekday.slice(1);
    const time = (minute || 0) === 0 ? `${hour}h` : `${hour}h${String(minute).padStart(2, '0')}`;

    return `${weekdayTitle}, ${day} de ${monthName}, às ${time}`;
};

const datetimeClass = 'mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <AppLayout title="Mercado">

        <div class="p-1 lg:p-4">
            <div class="mx-auto max-w-2xl space-y-4">
                <p
                    v-if="flashSuccess"
                    class="rounded-xl bg-green-50 px-4 py-3 text-sm font-medium text-green-800 shadow"
                >
                    {{ flashSuccess }}
                </p>

                <div v-if="current_game" class="rounded-xl bg-white p-4 shadow">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">
                            Jogo atual — rodada {{ current_game.round }}
                        </h3>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ current_game.status_label }}
                        </span>
                    </div>

                    <form class="space-y-4" @submit.prevent="saveCurrentGame">
                        <div>
                            <InputLabel for="current_opens_at" value="Abertura do mercado" required />
                            <input
                                id="current_opens_at"
                                v-model="currentForm.opens_at"
                                type="datetime-local"
                                :disabled="!current_game.can_edit"
                                :class="datetimeClass"
                            >
                            <p v-if="weekdayLabel(currentForm.opens_at)" class="mt-1 text-sm font-bold text-gray-800">
                                {{ weekdayLabel(currentForm.opens_at) }}
                            </p>
                            <InputError class="mt-1" :message="currentForm.errors.opens_at" />
                        </div>

                        <div>
                            <InputLabel for="current_starts_at" value="Horário do jogo" required />
                            <input
                                id="current_starts_at"
                                v-model="currentForm.starts_at"
                                type="datetime-local"
                                :disabled="!current_game.can_edit"
                                :class="datetimeClass"
                            >
                            <p v-if="weekdayLabel(currentForm.starts_at)" class="mt-1 text-sm font-bold text-gray-800">
                                {{ weekdayLabel(currentForm.starts_at) }}
                            </p>
                            <InputError class="mt-1" :message="currentForm.errors.starts_at" />
                        </div>

                        <div v-if="current_game.can_edit" class="flex justify-end">
                            <PrimaryButton :disabled="currentForm.processing">
                                Salvar horários
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="rounded-xl bg-white p-4 shadow">
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">
                            Próxima rodada {{ schedule.round }}
                        </h3>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="has_pending ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'"
                        >
                            {{ has_pending ? 'Agendada' : 'Padrão' }}
                        </span>
                    </div>
                    <p class="mb-4 text-sm text-gray-500">
                        Por padrão o jogo é criado quinta às 12h, o mercado abre sexta às 17h e a partida é na segunda às 21h.
                    </p>

                    <form class="space-y-4" @submit.prevent="saveSchedule">
                        <div>
                            <InputLabel for="creates_at" value="Quando criar o jogo" required />
                            <input
                                id="creates_at"
                                v-model="form.creates_at"
                                type="datetime-local"
                                required
                                :class="datetimeClass"
                            >
                            <p v-if="weekdayLabel(form.creates_at)" class="mt-1 text-sm font-bold text-gray-800">
                                {{ weekdayLabel(form.creates_at) }}
                            </p>
                            <InputError class="mt-1" :message="form.errors.creates_at" />
                        </div>

                        <div>
                            <InputLabel for="opens_at" value="Quando abrir o mercado" required />
                            <input
                                id="opens_at"
                                v-model="form.opens_at"
                                type="datetime-local"
                                required
                                :class="datetimeClass"
                            >
                            <p v-if="weekdayLabel(form.opens_at)" class="mt-1 text-sm font-bold text-gray-800">
                                {{ weekdayLabel(form.opens_at) }}
                            </p>
                            <InputError class="mt-1" :message="form.errors.opens_at" />
                        </div>

                        <div>
                            <InputLabel for="starts_at" value="Quando será o jogo" required />
                            <input
                                id="starts_at"
                                v-model="form.starts_at"
                                type="datetime-local"
                                required
                                :class="datetimeClass"
                            >
                            <p v-if="weekdayLabel(form.starts_at)" class="mt-1 text-sm font-bold text-gray-800">
                                {{ weekdayLabel(form.starts_at) }}
                            </p>
                            <InputError class="mt-1" :message="form.errors.starts_at" />
                        </div>

                        <InputError :message="form.errors.schedule" />

                        <div class="flex flex-wrap justify-end gap-2">
                            <SecondaryButton
                                type="button"
                                :disabled="form.processing"
                                @click="createNow"
                            >
                                Criar jogo agora
                            </SecondaryButton>
                            <PrimaryButton :disabled="form.processing">
                                {{ has_pending ? 'Atualizar agendamento' : 'Salvar agendamento' }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

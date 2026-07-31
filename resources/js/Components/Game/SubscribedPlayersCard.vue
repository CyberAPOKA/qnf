<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';

const props = defineProps({
    players: {
        type: Array,
        default: () => [],
    },
    gameId: {
        type: Number,
        default: null,
    },
    editable: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: 'Inscritos',
    },
});

const removeForm = useForm({ user_id: null });
const confirmVisible = ref(false);
const playerToRemove = ref(null);

const hasPlayers = computed(() => props.players.length > 0);

const askRemove = (player) => {
    playerToRemove.value = player;
    confirmVisible.value = true;
};

const confirmRemove = () => {
    if (!props.gameId || !playerToRemove.value) return;

    removeForm.user_id = playerToRemove.value.id;
    removeForm.post(route('games.remove-player', props.gameId), {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => {
            confirmVisible.value = false;
            playerToRemove.value = null;
        },
    });
};
</script>

<template>
    <section v-if="hasPlayers" class="roster">
        <div class="roster__inner">
            <span class="roster__corner roster__corner--tl" aria-hidden="true" />
            <span class="roster__corner roster__corner--tr" aria-hidden="true" />
            <span class="roster__corner roster__corner--bl" aria-hidden="true" />
            <span class="roster__corner roster__corner--br" aria-hidden="true" />
            <span class="roster__edge roster__edge--top" aria-hidden="true" />
            <span class="roster__edge roster__edge--bottom" aria-hidden="true" />

            <header class="roster__header">
                <span class="roster__rule roster__rule--left" aria-hidden="true" />
                <h3 class="roster__title">{{ title }}</h3>
                <span class="roster__rule roster__rule--right" aria-hidden="true" />
            </header>

            <span class="roster__notch" aria-hidden="true" />

            <div class="roster__grid">
                <article v-for="player in players" :key="player.id" class="rplayer">
                    <div class="rplayer__frame">
                        <div class="rplayer__body">
                            <svg class="rplayer__hairline" viewBox="0 0 100 125" preserveAspectRatio="none"
                                aria-hidden="true">
                                <polygon points="12,4 88,4 96,12 96,113 88,121 12,121 4,113 4,12" />
                            </svg>
                            <span class="rplayer__glow" aria-hidden="true" />

                            <img v-if="player.photo_front" class="rplayer__img" :src="player.photo_front"
                                :alt="player.name" loading="lazy" decoding="async">
                            <span v-else class="rplayer__initial">{{ player.initial || '?' }}</span>

                            <span class="rplayer__status">
                                <i class="fa-solid fa-circle-check" aria-hidden="true" />
                                Confirmado
                            </span>

                            <span class="rplayer__corner rplayer__corner--tl" aria-hidden="true" />
                            <span class="rplayer__corner rplayer__corner--tr" aria-hidden="true" />
                            <span class="rplayer__corner rplayer__corner--bl" aria-hidden="true" />
                            <span class="rplayer__corner rplayer__corner--br" aria-hidden="true" />
                            <span class="rplayer__stud rplayer__stud--left" aria-hidden="true" />
                            <span class="rplayer__stud rplayer__stud--right" aria-hidden="true" />
                        </div>
                    </div>

                    <div class="rplayer__plate">
                        <span class="rplayer__name">{{ player.name }}</span>
                    </div>

                    <span class="rplayer__chevron" aria-hidden="true" />

                    <button v-if="editable" type="button" class="rplayer__remove" :title="`Remover ${player.name}`"
                        @click="askRemove(player)">
                        <i class="fa-solid fa-xmark" aria-hidden="true" />
                    </button>
                </article>
            </div>
        </div>

        <Dialog v-model:visible="confirmVisible" modal header="Remover jogador" :style="{ width: '20rem' }">
            <p class="text-sm text-gray-700">
                Remover <span class="font-semibold">{{ playerToRemove?.name }}</span> da lista?
            </p>
            <p class="mt-1 text-xs text-gray-500">O jogador não poderá se inscrever novamente.</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Cancelar" severity="secondary" size="small" @click="confirmVisible = false" />
                    <Button label="Remover" severity="danger" size="small" :loading="removeForm.processing"
                        @click="confirmRemove" />
                </div>
            </template>
        </Dialog>
    </section>
</template>

<style scoped>
.roster {
    --gold: #f0c65c;
    --gold-deep: #a97b1b;
    --cut: 22px;

    position: relative;
    padding: 2px;
    background: linear-gradient(150deg,
        rgba(240, 198, 92, 0.9) 0%,
        rgba(59, 130, 246, 0.55) 22%,
        rgba(139, 92, 246, 0.5) 45%,
        rgba(240, 198, 92, 0.9) 70%,
        rgba(59, 130, 246, 0.55) 100%);
    filter: drop-shadow(0 0 16px rgba(240, 198, 92, 0.12)) drop-shadow(0 14px 30px rgba(0, 0, 0, 0.5));
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

.roster__inner {
    --cut: 20px;

    position: relative;
    padding: 16px 12px 22px;
    background:
        radial-gradient(120% 80% at 50% 0%, rgba(56, 189, 248, 0.1), transparent 60%),
        radial-gradient(100% 70% at 50% 110%, rgba(139, 92, 246, 0.14), transparent 65%),
        linear-gradient(180deg, #070d1c 0%, #04070f 100%);
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

.roster__corner {
    position: absolute;
    width: 20px;
    height: 20px;
    border: 2px solid rgba(240, 198, 92, 0.85);
    filter: drop-shadow(0 0 6px rgba(240, 198, 92, 0.55));
    pointer-events: none;
}

.roster__corner--tl {
    top: 16px;
    left: 5px;
    border-right: 0;
    border-bottom: 0;
}

.roster__corner--tr {
    top: 16px;
    right: 5px;
    border-bottom: 0;
    border-left: 0;
}

.roster__corner--bl {
    bottom: 16px;
    left: 5px;
    border-top: 0;
    border-right: 0;
}

.roster__corner--br {
    right: 5px;
    bottom: 16px;
    border-top: 0;
    border-left: 0;
}

.roster__edge {
    position: absolute;
    left: 50%;
    width: 110px;
    height: 6px;
    background: linear-gradient(90deg, rgba(240, 198, 92, 0.1), var(--gold) 40%, var(--gold) 60%, rgba(240, 198, 92, 0.1));
    transform: translateX(-50%);
    filter: drop-shadow(0 0 6px rgba(240, 198, 92, 0.6));
    pointer-events: none;
}

.roster__edge--top {
    top: 0;
    clip-path: polygon(0 0, 100% 0, calc(100% - 14px) 100%, 14px 100%);
}

.roster__edge--bottom {
    bottom: 0;
    clip-path: polygon(14px 0, calc(100% - 14px) 0, 100% 100%, 0 100%);
}

.roster__header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 0 4px;
}

.roster__title {
    margin: 0;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 0.32em;
    line-height: 1;
    text-indent: 0.32em;
    text-transform: uppercase;
    white-space: nowrap;
    background: linear-gradient(180deg, #fff6d5 0%, #f5cc5c 45%, #c9901c 70%, #ffe9a8 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    filter: drop-shadow(0 2px 0 rgba(0, 0, 0, 0.6));
}

/* Barras laterais com ponta chanfrada */
.roster__rule {
    flex: 1 1 auto;
    max-width: 120px;
    height: 4px;
    background: linear-gradient(90deg, transparent, rgba(240, 198, 92, 0.85));
    filter: drop-shadow(0 0 6px rgba(240, 198, 92, 0.45));
}

.roster__rule--left {
    clip-path: polygon(0 0, 100% 0, 100% 100%, 8px 100%);
}

.roster__rule--right {
    background: linear-gradient(90deg, rgba(240, 198, 92, 0.85), transparent);
    clip-path: polygon(0 0, 100% 0, calc(100% - 8px) 100%, 0 100%);
}

.roster__notch {
    display: block;
    width: 26px;
    height: 9px;
    margin: 8px auto 0;
    background: linear-gradient(180deg, var(--gold), rgba(240, 198, 92, 0.35));
    filter: drop-shadow(0 0 6px rgba(240, 198, 92, 0.6));
    clip-path: polygon(0 0, 100% 0, 50% 100%);
}

.roster__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-top: 16px;
}

.rplayer {
    position: relative;
    padding-bottom: 14px;
}

.rplayer__frame {
    --cut: 18px;

    position: relative;
    padding: 3px;
    background: linear-gradient(150deg,
        #fbeab4 0%,
        #d5a533 16%,
        #8a6418 34%,
        #f4d987 56%,
        #b3811f 76%,
        #ffeeb8 100%);
    filter: drop-shadow(0 0 10px rgba(240, 198, 92, 0.18));
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

.rplayer__body {
    --cut: 16px;

    position: relative;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    aspect-ratio: 4 / 5;
    overflow: hidden;
    background: radial-gradient(70% 55% at 50% 78%, rgba(240, 198, 92, 0.16), transparent 70%), #05070e;
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

/* Fio dourado interno acompanhando o chanfro (SVG para o traço vazado sobre a foto) */
.rplayer__hairline {
    position: absolute;
    inset: 0;
    z-index: 3;
    width: 100%;
    height: 100%;
    filter: drop-shadow(0 0 4px rgba(240, 198, 92, 0.45));
    pointer-events: none;
}

.rplayer__hairline polygon {
    fill: none;
    stroke: rgba(240, 198, 92, 0.8);
    stroke-width: 1.2;
    vector-effect: non-scaling-stroke;
}

.rplayer__glow {
    position: absolute;
    right: 0;
    bottom: -12%;
    left: 0;
    z-index: 1;
    height: 70%;
    background: radial-gradient(60% 60% at 50% 60%, rgba(240, 198, 92, 0.28), transparent 70%);
    pointer-events: none;
}

.rplayer__img {
    position: relative;
    z-index: 2;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 50% -5%;
}

.rplayer__initial {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    margin-bottom: 28%;
    border-radius: 999px;
    background: linear-gradient(180deg, rgba(240, 198, 92, 0.3), rgba(169, 123, 27, 0.15));
    box-shadow: inset 0 0 0 1.5px rgba(240, 198, 92, 0.65);
    color: #ffe9a8;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.6rem;
    font-weight: 700;
}

.rplayer__status {
    position: absolute;
    bottom: 12px;
    left: 50%;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 14px;
    background: linear-gradient(180deg, rgba(8, 12, 24, 0.95), rgba(4, 6, 12, 0.95));
    box-shadow: inset 0 0 0 1px rgba(240, 198, 92, 0.55);
    color: #f3d07a;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    white-space: nowrap;
    transform: translateX(-50%);
    clip-path: polygon(7px 0, calc(100% - 7px) 0, 100% 50%, calc(100% - 7px) 100%, 7px 100%, 0 50%);
}

.rplayer__status i {
    font-size: 0.75rem;
    color: var(--gold);
}

/* Cantos em "L" e travas laterais dentro da moldura */
.rplayer__corner {
    position: absolute;
    z-index: 4;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(240, 198, 92, 0.9);
    filter: drop-shadow(0 0 5px rgba(240, 198, 92, 0.5));
    pointer-events: none;
}

.rplayer__corner--tl {
    top: 12px;
    left: 2px;
    border-right: 0;
    border-bottom: 0;
}

.rplayer__corner--tr {
    top: 12px;
    right: 2px;
    border-bottom: 0;
    border-left: 0;
}

.rplayer__corner--bl {
    bottom: 12px;
    left: 2px;
    border-top: 0;
    border-right: 0;
}

.rplayer__corner--br {
    right: 2px;
    bottom: 12px;
    border-top: 0;
    border-left: 0;
}

.rplayer__stud {
    position: absolute;
    top: 50%;
    z-index: 4;
    width: 5px;
    height: 26px;
    background: linear-gradient(180deg, rgba(240, 198, 92, 0.25), var(--gold), rgba(240, 198, 92, 0.25));
    transform: translateY(-50%);
    filter: drop-shadow(0 0 5px rgba(240, 198, 92, 0.55));
    pointer-events: none;
}

.rplayer__stud--left {
    left: 0;
    clip-path: polygon(0 0, 100% 7px, 100% calc(100% - 7px), 0 100%);
}

.rplayer__stud--right {
    right: 0;
    clip-path: polygon(100% 0, 0 7px, 0 calc(100% - 7px), 100% 100%);
}

/* Placa do nome sobrepondo a base da moldura */
.rplayer__plate {
    position: relative;
    z-index: 5;
    margin: -16px 6px 0;
    padding: 2px;
    background: linear-gradient(150deg, #fbeab4, #b3811f 45%, #ffeeb8);
    filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.55));
    clip-path: polygon(12px 0, calc(100% - 12px) 0, 100% 100%, 0 100%);
}

.rplayer__name {
    display: block;
    padding: 7px 10px 6px;
    background: linear-gradient(180deg, #0b1224 0%, #04070f 100%);
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.1;
    text-align: center;
    text-transform: uppercase;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    clip-path: polygon(11px 0, calc(100% - 11px) 0, 100% 100%, 0 100%);
}

.rplayer__chevron {
    position: absolute;
    bottom: 0;
    left: 50%;
    width: 30px;
    height: 11px;
    background: linear-gradient(180deg, rgba(240, 198, 92, 0.95), rgba(59, 130, 246, 0.5));
    transform: translateX(-50%);
    filter: drop-shadow(0 0 6px rgba(240, 198, 92, 0.5));
    pointer-events: none;
    clip-path: polygon(0 0, 100% 0, 50% 100%);
}

.rplayer__remove {
    position: absolute;
    top: -6px;
    right: -6px;
    z-index: 6;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border: 0;
    background: linear-gradient(180deg, #ef4444, #991b1b);
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
    color: #fff;
    font-size: 0.75rem;
    cursor: pointer;
    clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
}

.rplayer__remove:hover {
    background: linear-gradient(180deg, #f87171, #b91c1c);
}

@media (min-width: 640px) {
    .roster__inner {
        padding: 20px 18px 26px;
    }

    .roster__title {
        font-size: 1.9rem;
    }

    .roster__grid {
        gap: 18px;
    }

    .rplayer__name {
        font-size: 1.1rem;
    }

    .rplayer__status {
        font-size: 0.68rem;
        padding: 5px 16px;
    }
}

@media (min-width: 1024px) {
    .roster__grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>

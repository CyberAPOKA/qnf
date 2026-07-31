<script setup>
import { computed } from 'vue';
import HudProgressBar from '@/Components/HudProgressBar.vue';

const props = defineProps({
    partner: {
        type: Object,
        required: true,
    },
});

const formatRate = (rate) => (rate == null ? '—' : `${rate.toFixed(1).replace('.', ',')}%`);

const rateTone = (rate) => {
    if (rate == null) return 'muted';
    if (rate >= 66) return 'success';
    if (rate >= 33) return 'warning';
    return 'danger';
};

const plural = (value, singular, pluralWord) => (value === 1 ? singular : pluralWord);

const sections = computed(() => [
    {
        key: 'together',
        title: 'Jogando juntos',
        icon: 'fa-solid fa-handshake-simple',
        variant: 'together',
        data: props.partner.together,
    },
    {
        key: 'against',
        title: 'Como adversários',
        icon: 'fa-solid fa-khanda',
        variant: 'against',
        data: props.partner.against,
    },
]);
</script>

<template>
    <article class="partner-card">
        <header class="partner-card__head">
            <div class="partner-card__avatar">
                <div class="partner-card__avatar-inner">
                    <img
                        v-if="partner.photo"
                        :src="partner.photo"
                        :alt="partner.name"
                        loading="lazy"
                        decoding="async"
                    >
                    <i v-else class="fa-solid fa-user" aria-hidden="true" />
                </div>
            </div>

            <div class="partner-card__identity">
                <h3 class="partner-card__name">
                    <span class="partner-card__name-text">{{ partner.name }}</span>
                    <i
                        v-if="partner.isGoalkeeper"
                        class="fa-solid fa-mitten partner-card__glove"
                        title="Goleiro"
                        aria-hidden="true"
                    />
                </h3>

                <div class="partner-card__meta">
                    <span class="partner-card__meta-item">
                        <i class="fa-solid fa-user-group" aria-hidden="true" />
                        <strong>{{ partner.gamesTogether }}</strong>
                        {{ plural(partner.gamesTogether, 'jogo junto', 'jogos juntos') }}
                    </span>
                    <span class="partner-card__meta-divider" aria-hidden="true" />
                    <span class="partner-card__meta-item">
                        <i class="fa-solid fa-khanda" aria-hidden="true" />
                        <strong>{{ partner.gamesAgainst }}</strong>
                        {{ plural(partner.gamesAgainst, 'confronto', 'confrontos') }}
                    </span>
                </div>
            </div>
        </header>

        <div class="partner-card__sections">
            <section
                v-for="section in sections"
                :key="section.key"
                class="partner-card__section"
                :class="`partner-card__section--${section.variant}`"
            >
                <h4 class="partner-card__section-title">
                    <i :class="section.icon" aria-hidden="true" />
                    {{ section.title }}
                </h4>

                <div class="partner-card__chips">
                    <span class="partner-card__chip partner-card__chip--win">
                        <strong>{{ section.data.wins }}</strong>
                        {{ plural(section.data.wins, 'vitória', 'vitórias') }}
                    </span>
                    <span class="partner-card__chip partner-card__chip--draw">
                        <strong>{{ section.data.draws }}</strong>
                        {{ plural(section.data.draws, 'empate', 'empates') }}
                    </span>
                    <span class="partner-card__chip partner-card__chip--loss">
                        <strong>{{ section.data.losses }}</strong>
                        {{ plural(section.data.losses, 'derrota', 'derrotas') }}
                    </span>
                </div>

                <p class="partner-card__rate-label">Aproveitamento</p>

                <div class="partner-card__rate">
                    <HudProgressBar class="partner-card__rate-bar" :value="section.data.rate" />
                    <span
                        class="partner-card__rate-value"
                        :class="`partner-card__rate-value--${rateTone(section.data.rate)}`"
                    >
                        {{ formatRate(section.data.rate) }}
                    </span>
                </div>
            </section>
        </div>
    </article>
</template>

<style scoped>
.partner-card {
    position: relative;
    padding: 14px;
    border-radius: 18px;
    background:
        linear-gradient(180deg, rgba(10, 17, 32, 0.94) 0%, rgba(5, 9, 18, 0.96) 100%);
    box-shadow:
        inset 0 0 0 1.5px rgba(56, 189, 248, 0.45),
        0 0 20px rgba(56, 189, 248, 0.12),
        0 14px 34px rgba(0, 0, 0, 0.45);
}

.partner-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 18px;
    padding: 1.5px;
    background: linear-gradient(135deg, rgba(56, 189, 248, 0.9), rgba(139, 92, 246, 0.85));
    -webkit-mask:
        linear-gradient(#000 0 0) content-box,
        linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

.partner-card__head {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Duas camadas: a externa é a borda, a interna recorta o conteúdo.
   Um inset box-shadow some nas diagonais do polígono. */
.partner-card__avatar {
    --hex: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);

    flex: 0 0 auto;
    width: 66px;
    height: 76px;
    padding: 2px;
    background: linear-gradient(180deg, #7dd3fc 0%, #38bdf8 45%, #1d4ed8 100%);
    clip-path: var(--hex);
    filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.45));
}

.partner-card__avatar-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    overflow: hidden;
    color: #38bdf8;
    font-size: 1.7rem;
    background: linear-gradient(180deg, rgba(29, 78, 216, 0.45), rgba(8, 14, 28, 0.95));
    clip-path: var(--hex);
}

.partner-card__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 50% 0;
    /* aproxima o enquadramento no rosto */
    transform: scale(1.55);
    transform-origin: 50% -5%; 

}

.partner-card__identity {
    flex: 1 1 auto;
    min-width: 0;
}

.partner-card__name {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    min-width: 0;
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.5rem;
    font-style: italic;
    font-weight: 900;
    letter-spacing: 0.02em;
    line-height: 1.1;
    text-transform: uppercase;
}

.partner-card__name-text {
    min-width: 0;
    overflow: hidden;
    /* compensa o avanço do itálico, que era cortado pelo overflow */
    padding-right: 0.18em;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.partner-card__glove {
    flex: 0 0 auto;
    color: #e2e8f0;
    font-size: 1.05rem;
    font-style: normal;
}

.partner-card__meta {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 8px;
    padding: 7px 10px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 10px;
    background: rgba(8, 14, 28, 0.75);
}

.partner-card__meta-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #94a3b8;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    white-space: nowrap;
}

.partner-card__meta-item i {
    color: #7dd3fc;
    font-size: 0.8rem;
}

.partner-card__meta-item strong {
    color: #e2e8f0;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.85rem;
}

.partner-card__meta-divider {
    flex: 0 0 auto;
    width: 1px;
    height: 16px;
    background: rgba(148, 163, 184, 0.25);
}

.partner-card__sections {
    display: grid;
    gap: 12px;
    margin-top: 12px;
}

.partner-card__section {
    --tone: #22c55e;
    --tone-rgb: 34, 197, 94;

    display: flex;
    flex-direction: column;
    padding: 12px;
    border: 1px solid rgba(var(--tone-rgb), 0.28);
    border-radius: 12px;
    background: rgba(7, 12, 24, 0.7);
}

.partner-card__section--against {
    --tone: #ef4444;
    --tone-rgb: 239, 68, 68;
}

.partner-card__section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 10px;
    color: var(--tone);
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1rem;
    font-style: italic;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    text-shadow: 0 0 12px rgba(var(--tone-rgb), 0.45);
}

.partner-card__chips {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 6px;
}

.partner-card__chip {
    --chip: #94a3b8;
    --chip-rgb: 148, 163, 184;

    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 6px 4px;
    border: 1px solid rgba(var(--chip-rgb), 0.35);
    border-radius: 8px;
    background: rgba(var(--chip-rgb), 0.1);
    color: rgba(var(--chip-rgb), 1);
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    white-space: nowrap;
}

.partner-card__chip strong {
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.85rem;
    font-weight: 800;
}

.partner-card__chip--win {
    --chip: #4ade80;
    --chip-rgb: 74, 222, 128;
}

.partner-card__chip--draw {
    --chip: #facc15;
    --chip-rgb: 250, 204, 21;
}

.partner-card__chip--loss {
    --chip: #f87171;
    --chip-rgb: 248, 113, 113;
}

.partner-card__rate-label {
    margin: auto 0 6px;
    padding-top: 12px;
    color: #94a3b8;
    font-size: 0.6rem;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
}

.partner-card__rate {
    display: flex;
    align-items: center;
    gap: 12px;
}

.partner-card__rate-bar {
    flex: 1 1 auto;
}

.partner-card__rate-value {
    flex: 0 0 auto;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 1.4rem;
    font-style: italic;
    font-weight: 800;
    line-height: 1;
}

.partner-card__rate-value--success {
    color: #4ade80;
    text-shadow: 0 0 14px rgba(74, 222, 128, 0.5);
}

.partner-card__rate-value--warning {
    color: #facc15;
    text-shadow: 0 0 14px rgba(250, 204, 21, 0.5);
}

.partner-card__rate-value--danger {
    color: #f87171;
    text-shadow: 0 0 14px rgba(248, 113, 113, 0.5);
}

.partner-card__rate-value--muted {
    color: #64748b;
}

@media (min-width: 640px) {
    .partner-card {
        padding: 18px;
    }

    .partner-card__name {
        font-size: 1.7rem;
    }

    .partner-card__chip {
        font-size: 0.68rem;
        padding: 7px 6px;
    }

    .partner-card__rate-value {
        font-size: 1.6rem;
    }
}

/* Card largo: identidade em linha e as duas seções lado a lado */
@media (min-width: 1024px) {
    .partner-card__identity {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .partner-card__meta {
        margin-top: 0;
        flex: 0 0 auto;
    }

    .partner-card__sections {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>

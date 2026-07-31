<script setup lang="ts">
import { computed } from 'vue'
import RankingRankBadge from '@/Components/Ranking/RankingRankBadge.vue'
import RankingPlayerPhoto from '@/Components/Ranking/RankingPlayerPhoto.vue'
import RankingPlayerIdentity from '@/Components/Ranking/RankingPlayerIdentity.vue'
import RankingPlayerStats from '@/Components/Ranking/RankingPlayerStats.vue'
import RankingPlayerForm from '@/Components/Ranking/RankingPlayerForm.vue'
import RankingPlayerStreakEffect from '@/Components/Ranking/RankingPlayerStreakEffect.vue'
import RankingStreakBorder from '@/Components/Ranking/RankingStreakBorder.vue'
import { getRankingStreakType } from '@/Components/Ranking/helpers/getRankingStreakType'
import { THEME_CLASS, type RankingPlayer } from '@/Components/Ranking/types'

const props = defineProps<{
    player: RankingPlayer
}>()

const streakType = computed(() => getRankingStreakType(props.player.win_streak))

const isPodium = computed(() =>
    props.player.theme === 'gold'
    || props.player.theme === 'silver'
    || props.player.theme === 'bronze',
)
</script>

<template>
    <article
        class="ranking-card relative grid items-center border-0 transition duration-150"
        :class="[
            THEME_CLASS[player.theme],
            {
                'ranking-card--podium': isPodium,
                'ranking-card--streak-fire': streakType === 'fire',
                'ranking-card--streak-ice': streakType === 'ice',
                'ranking-card--streak-purple': streakType === 'purple',
            },
        ]"
    >
        <div class="ranking-card__frame pointer-events-none absolute inset-0 z-0" aria-hidden="true" />

        <div class="ranking-card__clipped pointer-events-none absolute inset-0 z-[1] overflow-hidden" aria-hidden="true">
            <RankingPlayerStreakEffect :type="streakType" />
            <RankingStreakBorder :type="streakType" />
            <div class="ranking-card__glow absolute top-1/2 left-[-60px] h-[180px] w-[300px] -translate-y-1/2 rounded-full" />
            <div class="ranking-card__pattern absolute top-0 right-0 h-full w-[260px]" />
            <div
                v-if="isPodium"
                class="ranking-card__photo-blend absolute inset-x-0 bottom-0 h-[42%]"
            />
        </div>

        <RankingRankBadge class="relative z-[2]" :rank="player.rank" :theme="player.theme" />

        <RankingPlayerPhoto
            class="relative"
            :class="isPodium ? 'z-[15]' : 'z-[2]'"
            :src="player.photo_front"
            :name="player.name"
            :initial="player.initial"
            :theme="player.theme"
        />

        <RankingPlayerIdentity
            class="relative z-[2]"
            :name="player.name"
            :win-streak="player.win_streak"
            :is-goalkeeper="player.isGoalkeeper"
        />

        <div class="relative z-[2] flex flex-col items-center justify-center gap-2 max-[950px]:pr-1">
            <RankingPlayerStats
                :points="player.points"
                :games="player.games"
                :movement="player.movement"
                :points-label="player.pointsLabel"
                :avg="player.avg"
            />

            <RankingPlayerForm
                v-if="player.form?.length"
                :form="player.form"
            />
        </div>
    </article>
</template>

<style scoped>
.ranking-card {
    --accent: #64748b;
    --accent-rgb: 100, 116, 139;
    --accent-light: #cbd5e1;
    --surface: #101624;
    --border-w: 1.5px;
    --cut: 14px;
    --streak-color: transparent;
    --streak-rgb: 0, 0, 0;

    overflow: hidden;
    grid-template-columns: 92px 190px minmax(150px, 1fr) auto;
    column-gap: 6px;
    padding-right: 20px;
    min-height: 104px;
    height: 104px;
}

.ranking-card--podium {
    overflow: visible !important;
    z-index: 3;
}

.ranking-card--podium:hover {
    z-index: 6;
}

.ranking-card__frame {
    background:
        linear-gradient(
            100deg,
            rgba(var(--accent-rgb), 0.18) 0%,
            rgba(9, 13, 25, 0.97) 38%,
            rgba(7, 11, 22, 0.98) 100%
        );
    box-shadow:
        inset 0 0 28px rgba(var(--accent-rgb), 0.08),
        0 0 18px rgba(var(--accent-rgb), 0.16);
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

.ranking-card__frame::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            120deg,
            transparent 0 28%,
            rgba(var(--accent-rgb), 0.08) 28% 28.4%,
            transparent 28.4% 58%,
            rgba(var(--accent-rgb), 0.07) 58% 58.4%,
            transparent 58.4%
        );
}

.ranking-card__frame::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    background: rgba(var(--accent-rgb), 0.9);
    opacity: 0.85;
    transition: background 180ms ease, opacity 180ms ease;
    clip-path: polygon(
        evenodd,
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut),
        var(--cut) 0,
        calc(var(--cut) + var(--border-w)) var(--border-w),
        var(--border-w) calc(var(--cut) + var(--border-w)),
        var(--border-w) calc(100% - var(--cut) - var(--border-w)),
        calc(var(--cut) + var(--border-w)) calc(100% - var(--border-w)),
        calc(100% - var(--cut) - var(--border-w)) calc(100% - var(--border-w)),
        calc(100% - var(--border-w)) calc(100% - var(--cut) - var(--border-w)),
        calc(100% - var(--border-w)) calc(var(--cut) + var(--border-w)),
        calc(100% - var(--cut) - var(--border-w)) var(--border-w),
        calc(var(--cut) + var(--border-w)) var(--border-w)
    );
}

.ranking-card__clipped {
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

.ranking-card:hover {
    z-index: 5;
    transform: translateY(-3px) scale(1.005);
}

.ranking-card:hover .ranking-card__frame {
    box-shadow:
        inset 0 0 34px rgba(var(--accent-rgb), 0.13),
        0 0 28px rgba(var(--accent-rgb), 0.35);
}

.ranking-card:hover .ranking-card__frame::after {
    background: var(--accent-light);
    opacity: 0.95;
}

.ranking-card__glow {
    background: rgba(var(--accent-rgb), 0.3);
    filter: blur(70px);
}

.ranking-card__pattern {
    opacity: 1;
    background-image:
        radial-gradient(
            circle at center,
            rgba(var(--accent-rgb), 0.5) 1px,
            transparent 1px
        );
    background-size: 14px 14px;
    mask-image: linear-gradient(to left, black, transparent);
}

/* Blend da base da foto em toda a largura do card (não só na coluna da foto) */
.ranking-card__photo-blend {
    z-index: 2;
    background:
        radial-gradient(
            ellipse 70% 90% at 22% 100%,
            rgba(7, 11, 22, 0.55) 0%,
            rgba(7, 11, 22, 0.2) 42%,
            transparent 72%
        ),
        linear-gradient(
            to top,
            rgba(7, 11, 22, 0.35) 0%,
            rgba(7, 11, 22, 0.12) 40%,
            transparent 100%
        );
}

.ranking-card--gold {
    --accent: #f59e0b;
    --accent-rgb: 245, 158, 11;
    --accent-light: #fde68a;
    --border-w: 4px;
}

.ranking-card--silver {
    --accent: #94a3b8;
    --accent-rgb: 148, 163, 184;
    --accent-light: #f1f5f9;
    --border-w: 3px;
}

.ranking-card--bronze {
    --accent: #b45309;
    --accent-rgb: 180, 83, 9;
    --accent-light: #fdba74;
    --border-w: 2px;
}

.ranking-card--default {
    --accent: #64748b;
    --accent-rgb: 100, 116, 139;
    --accent-light: #cbd5e1;
}

.ranking-card--streak-fire {
    --streak-color: #ff8a00;
    --streak-rgb: 255, 106, 0;
}

.ranking-card--streak-fire .ranking-card__frame {
    background:
        linear-gradient(
            100deg,
            rgba(255, 106, 0, 0.34) 0%,
            rgba(46, 20, 5, 0.96) 30%,
            rgba(9, 13, 25, 0.98) 66%,
            rgba(7, 11, 22, 0.98) 100%
        );
    box-shadow:
        inset 0 0 34px rgba(255, 106, 0, 0.26),
        0 0 16px rgba(255, 106, 0, 0.55),
        0 0 34px rgba(245, 158, 11, 0.24);
}

.ranking-card--streak-ice {
    --streak-color: #38bdf8;
    --streak-rgb: 56, 189, 248;
}

.ranking-card--streak-ice .ranking-card__frame {
    background:
        linear-gradient(
            100deg,
            rgba(14, 165, 233, 0.4) 0%,
            rgba(12, 40, 62, 0.96) 32%,
            rgba(9, 13, 25, 0.98) 66%,
            rgba(7, 11, 22, 0.98) 100%
        );
    box-shadow:
        inset 0 0 36px rgba(56, 189, 248, 0.24),
        0 0 16px rgba(125, 211, 252, 0.5),
        0 0 36px rgba(14, 165, 233, 0.28);
}

.ranking-card--streak-purple {
    --streak-color: #a855f7;
    --streak-rgb: 168, 85, 247;
}

.ranking-card--streak-purple .ranking-card__frame {
    background:
        linear-gradient(
            100deg,
            rgba(126, 34, 206, 0.5) 0%,
            rgba(42, 16, 72, 0.96) 34%,
            rgba(9, 13, 25, 0.98) 68%,
            rgba(7, 11, 22, 0.98) 100%
        );
    box-shadow:
        inset 0 0 42px rgba(168, 85, 247, 0.28),
        0 0 20px rgba(168, 85, 247, 0.72),
        0 0 46px rgba(126, 34, 206, 0.34);
}

.ranking-card--streak-fire .ranking-card__frame::after {
    background: linear-gradient(90deg, #ff6a00, #ffd166, #ff6a00, #ffb000);
    opacity: 1;
    filter: drop-shadow(0 0 6px rgba(255, 106, 0, 0.9));
}

.ranking-card--streak-ice .ranking-card__frame::after {
    background: linear-gradient(90deg, #0ea5e9, #e0f2fe, #38bdf8, #7dd3fc);
    opacity: 1;
    filter: drop-shadow(0 0 7px rgba(56, 189, 248, 0.9));
}

.ranking-card--streak-purple .ranking-card__frame::after {
    background: linear-gradient(90deg, #7c3aed, #d8b4fe, #a855f7, #6d28d9);
    opacity: 1;
    filter: drop-shadow(0 0 8px rgba(168, 85, 247, 0.95));
}

@media (max-width: 950px) {
    .ranking-card {
        grid-template-columns: 56px 112px minmax(0, 1fr) auto;
        column-gap: 4px;
        --cut: 10px;
        padding-inline: 4px;
        height: 110px;
        min-height: 110px;
    }

    .ranking-card--podium {
        grid-template-columns: 56px 110px minmax(0, 1fr) auto;
        height: 115px;
        min-height: 115px;
    }
}
</style>

# Implementação de efeitos visuais para Win Streak no ranking

## Objetivo

Adicionar efeitos visuais especiais no componente principal dos cards de ranking para jogadores que possuem:

- 3 vitórias seguidas: efeito de fogo laranja.
- 5 ou mais vitórias seguidas: efeito de aura roxa.

A implementação deve manter a estrutura atual dos componentes, preservar a responsividade e não alterar visualmente os cards sem sequência especial.

O componente principal atual é:

```text
RankingPlayerCard.vue
```

Os efeitos devem ser renderizados como camadas internas do card, sem bloquear cliques, sem prejudicar a leitura e sem cobrir foto, nome, pontos, jogos ou histórico de resultados.

---

## Arquivos WEBM necessários

Adicionar os seguintes arquivos:

```text
public/videos/ranking/fire-streak.webm
public/videos/ranking/purple-aura-streak.webm
```

Nomes recomendados para pesquisar e baixar:

```text
fire-streak.webm
purple-aura-streak.webm
```

Também podem ser encontrados usando pesquisas como:

```text
transparent fire overlay webm
fire particles transparent webm
purple energy aura transparent webm
purple smoke energy transparent webm
purple lightning overlay transparent webm
```

Requisitos dos vídeos:

- Formato WEBM.
- Loop contínuo.
- Fundo transparente.
- Sem áudio.
- Resolução mínima recomendada de 1280 × 256.
- Proporção horizontal.
- Duração entre 2 e 8 segundos.
- Codec VP8 ou VP9 com canal alpha.
- Arquivo otimizado para web.
- Tamanho recomendado inferior a 4 MB por vídeo.

Os arquivos finais devem obrigatoriamente usar estes nomes:

```text
fire-streak.webm
purple-aura-streak.webm
```

---

## Regras de negócio

Adicionar ao tipo `RankingPlayer` uma propriedade responsável pela sequência atual:

```ts
win_streak: number
```

Regras:

```text
win_streak < 3
Sem efeito especial.

win_streak >= 3 e win_streak < 5
Efeito de fogo.
Texto: WIN STREAK {win_streak}

win_streak >= 5
Efeito de aura roxa.
Texto: WIN STREAK {win_streak}
```

Não calcular a sequência pelo frontend caso esse valor já possa ser retornado pela API.

Priorizar o valor retornado pelo backend.

Caso a API ainda não retorne o valor, criar um helper isolado para calcular a sequência a partir de `player.form`, considerando vitórias consecutivas do resultado mais recente para trás.

---

## Estrutura esperada

Criar os seguintes arquivos:

```text
resources/js/Components/Ranking/RankingPlayerStreakEffect.vue
resources/js/Components/Ranking/helpers/getRankingStreakType.ts
```

Alterar:

```text
resources/js/Components/Ranking/RankingPlayerCard.vue
resources/js/Components/Ranking/types.ts
```

Não mover responsabilidades dos componentes existentes.

---

## Tipos

Atualizar `types.ts`.

```ts
export type RankingStreakType = 'fire' | 'purple' | null

export interface RankingPlayer {
    rank: number
    name: string
    photo_front: string | null
    initial: string
    points: number
    games: number
    movement: number
    form?: string[]
    theme: string
    win_streak: number
}
```

Ajustar os tipos reais existentes sem duplicar interfaces.

---

## Helper

Criar:

```text
resources/js/Components/Ranking/helpers/getRankingStreakType.ts
```

Conteúdo esperado:

```ts
import type { RankingStreakType } from '@/Components/Ranking/types'

export function getRankingStreakType(winStreak: number): RankingStreakType {
    if (winStreak >= 5) {
        return 'purple'
    }

    if (winStreak >= 3) {
        return 'fire'
    }

    return null
}
```

---

## Novo componente de efeito

Criar:

```text
resources/js/Components/Ranking/RankingPlayerStreakEffect.vue
```

Responsabilidades:

- Renderizar o vídeo WEBM correto.
- Renderizar o texto `WIN STREAK {number}`.
- Renderizar camadas extras de partículas e iluminação.
- Não renderizar nada quando não existir efeito.
- Não possuir lógica de negócio além de escolher a apresentação pelo tipo recebido.
- Aplicar `pointer-events-none`.
- Aplicar `aria-hidden="true"` nos elementos puramente decorativos.
- Respeitar `prefers-reduced-motion`.

Implementação esperada:

```vue
<script setup lang="ts">
import type { RankingStreakType } from '@/Components/Ranking/types'

defineProps<{
    type: RankingStreakType
    count: number
}>()
</script>

<template>
    <div
        v-if="type"
        class="ranking-streak"
        :class="`ranking-streak--${type}`"
        aria-hidden="true"
    >
        <video
            class="ranking-streak__video"
            autoplay
            muted
            loop
            playsinline
            preload="metadata"
        >
            <source
                :src="
                    type === 'fire'
                        ? '/videos/ranking/fire-streak.webm'
                        : '/videos/ranking/purple-aura-streak.webm'
                "
                type="video/webm"
            >
        </video>

        <div class="ranking-streak__overlay" />
        <div class="ranking-streak__particles" />
        <div class="ranking-streak__energy" />

        <div class="ranking-streak__label">
            <span>WIN STREAK</span>
            <strong>{{ count }}</strong>
        </div>
    </div>
</template>

<style scoped>
.ranking-streak {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
}

.ranking-streak__video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.62;
    mix-blend-mode: screen;
}

.ranking-streak__overlay {
    position: absolute;
    inset: 0;
}

.ranking-streak__particles {
    position: absolute;
    inset: 0;
    opacity: 0.7;
}

.ranking-streak__energy {
    position: absolute;
    inset: 0;
}

.ranking-streak__label {
    position: absolute;
    top: 50%;
    left: clamp(310px, 38%, 520px);
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 220px;
    padding: 8px 18px;
    border: 1px solid currentColor;
    font-style: italic;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    transform: translateY(12px) skewX(-10deg);
    backdrop-filter: blur(8px);
}

.ranking-streak__label span,
.ranking-streak__label strong {
    transform: skewX(10deg);
}

.ranking-streak__label strong {
    font-size: 24px;
    line-height: 1;
}

.ranking-streak--fire {
    color: #ffd166;
}

.ranking-streak--fire .ranking-streak__video {
    opacity: 0.72;
}

.ranking-streak--fire .ranking-streak__overlay {
    background:
        radial-gradient(
            circle at 18% 70%,
            rgba(255, 106, 0, 0.5),
            transparent 34%
        ),
        linear-gradient(
            90deg,
            rgba(255, 106, 0, 0.34),
            rgba(245, 158, 11, 0.08) 48%,
            transparent 78%
        );
}

.ranking-streak--fire .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(255, 211, 102, 0.95) 1px, transparent 1px),
        radial-gradient(circle, rgba(255, 95, 31, 0.7) 1px, transparent 1px);
    background-position:
        0 0,
        7px 9px;
    background-size:
        22px 22px,
        31px 31px;
    mask-image: linear-gradient(to right, black, transparent 78%);
}

.ranking-streak--fire .ranking-streak__energy {
    background:
        linear-gradient(
            115deg,
            transparent 0 20%,
            rgba(255, 106, 0, 0.24) 20.2% 20.8%,
            transparent 21% 35%,
            rgba(255, 191, 0, 0.18) 35.2% 35.8%,
            transparent 36%
        );
}

.ranking-streak--fire .ranking-streak__label {
    background:
        linear-gradient(
            90deg,
            rgba(255, 106, 0, 0.24),
            rgba(17, 10, 4, 0.88)
        );
    box-shadow:
        0 0 16px rgba(255, 106, 0, 0.65),
        inset 0 0 12px rgba(255, 166, 0, 0.2);
}

.ranking-streak--purple {
    color: #e9d5ff;
}

.ranking-streak--purple .ranking-streak__video {
    opacity: 0.78;
}

.ranking-streak--purple .ranking-streak__overlay {
    background:
        radial-gradient(
            circle at 30% 50%,
            rgba(168, 85, 247, 0.56),
            transparent 40%
        ),
        linear-gradient(
            90deg,
            rgba(88, 28, 135, 0.52),
            rgba(76, 29, 149, 0.18) 55%,
            transparent 82%
        );
}

.ranking-streak--purple .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(216, 180, 254, 0.95) 1px, transparent 1px),
        radial-gradient(circle, rgba(139, 92, 246, 0.72) 1px, transparent 1px);
    background-position:
        0 0,
        9px 11px;
    background-size:
        19px 19px,
        28px 28px;
    mask-image: linear-gradient(to right, black, transparent 85%);
}

.ranking-streak--purple .ranking-streak__energy {
    background:
        linear-gradient(
            116deg,
            transparent 0 18%,
            rgba(192, 132, 252, 0.32) 18.2% 18.8%,
            transparent 19% 37%,
            rgba(124, 58, 237, 0.28) 37.2% 37.8%,
            transparent 38% 58%,
            rgba(168, 85, 247, 0.2) 58.2% 58.8%,
            transparent 59%
        );
    filter: blur(0.4px);
}

.ranking-streak--purple .ranking-streak__label {
    background:
        linear-gradient(
            90deg,
            rgba(126, 34, 206, 0.35),
            rgba(17, 8, 31, 0.9)
        );
    box-shadow:
        0 0 20px rgba(168, 85, 247, 0.82),
        inset 0 0 14px rgba(216, 180, 254, 0.18);
}

@media (max-width: 950px) {
    .ranking-streak__label {
        top: auto;
        right: 128px;
        bottom: 7px;
        left: auto;
        min-width: auto;
        padding: 4px 10px;
        font-size: 9px;
        transform: skewX(-10deg);
    }

    .ranking-streak__label strong {
        font-size: 15px;
    }

    .ranking-streak__video {
        opacity: 0.45;
    }
}

@media (prefers-reduced-motion: reduce) {
    .ranking-streak__video {
        display: none;
    }
}
</style>
```

---

## Alteração no componente principal

Atualizar `RankingPlayerCard.vue`.

Adicionar imports:

```ts
import RankingPlayerStreakEffect from '@/Components/Ranking/RankingPlayerStreakEffect.vue'
import { getRankingStreakType } from '@/Components/Ranking/helpers/getRankingStreakType'
```

Não usar diretamente `defineProps` sem variável.

Alterar para:

```ts
const props = defineProps<{
    player: RankingPlayer
}>()

const streakType = computed(() => {
    return getRankingStreakType(props.player.win_streak)
})
```

Adicionar:

```ts
import { computed } from 'vue'
```

No elemento `article`, adicionar classes condicionais:

```vue
:class="[
    THEME_CLASS[player.theme],
    {
        'ranking-card--streak-fire': streakType === 'fire',
        'ranking-card--streak-purple': streakType === 'purple',
    },
]"
```

Adicionar o componente logo após a abertura do `article`, antes das demais camadas:

```vue
<RankingPlayerStreakEffect
    :type="streakType"
    :count="player.win_streak"
/>
```

As camadas normais devem continuar existindo:

```vue
<div class="ranking-card__glow" />
<div class="ranking-card__pattern" />
```

Os componentes de conteúdo devem ficar acima do efeito.

Aplicar `relative z-[2]` nos wrappers de conteúdo caso necessário.

Não usar `z-index` maior que a borda `::after`.

---

## CSS do card principal

Adicionar novas variáveis:

```css
.ranking-card {
    --streak-color: transparent;
    --streak-rgb: 0, 0, 0;
}
```

Adicionar os modificadores:

```css
.ranking-card--streak-fire {
    --streak-color: #ff8a00;
    --streak-rgb: 255, 106, 0;
    --border-w: 2px;

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

.ranking-card--streak-purple {
    --streak-color: #a855f7;
    --streak-rgb: 168, 85, 247;
    --border-w: 2.5px;

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
```

Alterar a borda do `::after` para considerar streak:

```css
.ranking-card--streak-fire::after {
    background:
        linear-gradient(
            90deg,
            #ff6a00,
            #ffd166,
            #ff6a00,
            #ffb000
        );
    opacity: 1;
    filter: drop-shadow(0 0 6px rgba(255, 106, 0, 0.9));
}

.ranking-card--streak-purple::after {
    background:
        linear-gradient(
            90deg,
            #7c3aed,
            #d8b4fe,
            #a855f7,
            #6d28d9
        );
    opacity: 1;
    filter: drop-shadow(0 0 8px rgba(168, 85, 247, 0.95));
}
```

Adicionar brilho animado na borda usando um elemento real ou pseudo-elemento adicional dentro do componente.

Como o card já usa `::before` e `::after`, criar:

```vue
<div
    v-if="streakType"
    class="ranking-card__streak-border"
    :class="`ranking-card__streak-border--${streakType}`"
/>
```

CSS:

```css
.ranking-card__streak-border {
    position: absolute;
    inset: 0;
    z-index: 9;
    overflow: hidden;
    pointer-events: none;
    clip-path: inherit;
}

.ranking-card__streak-border::before {
    content: '';
    position: absolute;
    inset: -80%;
    background:
        conic-gradient(
            from 0deg,
            transparent 0deg 250deg,
            rgba(255, 255, 255, 0.95) 278deg,
            transparent 312deg 360deg
        );
    animation: ranking-streak-border-spin 3.2s linear infinite;
}

.ranking-card__streak-border::after {
    content: '';
    position: absolute;
    inset: 3px;
    background: #080d18;
    clip-path: inherit;
}

.ranking-card__streak-border--fire {
    color: #ff8a00;
}

.ranking-card__streak-border--purple {
    color: #a855f7;
}

@keyframes ranking-streak-border-spin {
    to {
        transform: rotate(360deg);
    }
}
```

Caso esse modelo de borda interfira no conteúdo, substituir por uma animação de `background-position` aplicada no `::after`.

---

## Camadas e z-index

Usar a seguinte ordem:

```text
z-index -2
Background base do card.

z-index -1
Glow e pattern existentes.

z-index 0
Vídeo e efeitos da sequência.

z-index 1
Overlay escuro para legibilidade.

z-index 2
Rank, foto, nome, estatísticas e resultados.

z-index 9
Brilho animado da borda.

z-index 10
Borda recortada final.
```

Nunca colocar o vídeo acima do conteúdo.

Nunca aplicar `filter` no elemento pai do card porque isso afeta todos os componentes filhos.

---

## Legibilidade

Criar uma camada escura do centro para a direita para manter os dados legíveis:

```css
.ranking-streak::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            90deg,
            transparent 0%,
            rgba(5, 8, 18, 0.2) 38%,
            rgba(5, 8, 18, 0.82) 72%,
            rgba(5, 8, 18, 0.94) 100%
        );
}
```

O efeito deve ser mais intenso atrás do rank e da foto.

A área de estatísticas e resultados deve continuar escura.

---

## Carregamento e desempenho

- Não importar os vídeos pelo JavaScript.
- Servir diretamente de `public/videos/ranking`.
- Usar `preload="metadata"`.
- Usar `muted`.
- Usar `playsinline`.
- Usar `loop`.
- Não renderizar o vídeo em cards sem streak.
- Não usar mais de um vídeo por card.
- Aplicar `contain: layout paint` no card se não quebrar a renderização.
- Evitar filtros com blur excessivo em dispositivos móveis.
- Reduzir opacidade e remover vídeo em `prefers-reduced-motion`.
- Garantir que o card continue funcionando no Safari do iPhone.
- WEBM com alpha pode não funcionar em algumas versões do Safari. Criar fallback CSS suficiente para o efeito continuar aceitável sem vídeo.
- Não bloquear a interface caso o vídeo falhe.

Adicionar fallback:

```vue
<video
    @error="videoFailed = true"
>
```

O fallback pode manter somente gradientes, partículas e brilhos CSS.

Não é obrigatório criar fallback MP4, pois MP4 não suporta transparência da mesma forma.

---

## Responsividade

No mobile:

- Diminuir a intensidade dos vídeos.
- Ocultar detalhes excessivos.
- Manter o texto `WIN STREAK`.
- Posicionar o selo abaixo do nome ou acima da linha dos resultados.
- Não aumentar a altura do card.
- Não cobrir os valores de pontos e jogos.
- Não permitir overflow horizontal.

---

## Backend

Caso o backend ainda não retorne `win_streak`, adicionar o campo no Resource responsável pelo ranking.

Exemplo:

```php
'win_streak' => $this->win_streak,
```

Se o valor não existir no model, calcular no serviço responsável pelo ranking.

A sequência deve considerar os resultados da partida mais recente para a mais antiga.

Parar a contagem no primeiro resultado diferente de vitória.

Não calcular por posição visual do array sem confirmar a ordem dos resultados.

---

## Critérios de aceite

- Card com menos de 3 vitórias não possui efeito especial.
- Card com 3 ou 4 vitórias possui efeito de fogo.
- Card com 5 ou mais vitórias possui aura roxa.
- O texto exibe o número real da sequência.
- O efeito roxo é visualmente mais forte que o efeito de fogo.
- O conteúdo permanece totalmente legível.
- A borda é diferente dos cards normais.
- Os efeitos não alteram os cards sem streak.
- Os vídeos são carregados somente quando necessários.
- O componente funciona no desktop e mobile.
- O layout atual não é quebrado.
- Nenhum código sem uso deve permanecer.
- Não adicionar comentários desnecessários no código.
- Manter Clean Code.
- Não duplicar regras de negócio.
- Entregar todos os arquivos completos alterados.
- Executar lint, typecheck e build ao finalizar.

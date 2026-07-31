<script setup>
defineProps({
  label: {
    type: String,
    default: 'CANCEL'
  },
  icon: {
    type: String,
    default: null
  }
});

const emit = defineEmits(['click']);
</script>

<template>
  <button class="fbtn" type="button" @click="emit('click')">
    <span class="fbtn__inner">
      <span class="fbtn__inline" aria-hidden="true" />
      <span class="fbtn__sheen" aria-hidden="true" />

      <span class="fbtn__content">
        <i v-if="icon" :class="icon" class="fbtn__icon" aria-hidden="true" />
        <span class="fbtn__label">
          <slot>{{ label }}</slot>
        </span>
      </span>
    </span>
  </button>
</template>

<style scoped>
.fbtn {
  --cut: 16px;
  --gold: #f0c65c;

  position: relative;
  display: block;
  width: 100%;
  padding: 4px;
  border: none;
  outline: none;
  background: linear-gradient(155deg,
      #fbeab4 0%,
      #d5a533 18%,
      #8a6418 38%,
      #f4d987 58%,
      #b3811f 78%,
      #ffeeb8 100%);
  cursor: pointer;
  transition: filter 0.25s ease, transform 0.1s ease;
  filter: drop-shadow(0 0 10px rgba(240, 198, 92, 0.22)) drop-shadow(0 8px 18px rgba(0, 0, 0, 0.5));
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

.fbtn__inner {
  --cut: 13px;

  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  min-height: 56px;
  padding: 10px 18px;
  overflow: hidden;
  background:
      radial-gradient(90% 120% at 50% -20%, rgba(88, 61, 255, 0.35), transparent 60%),
      radial-gradient(80% 120% at 50% 120%, rgba(37, 99, 235, 0.28), transparent 65%),
      linear-gradient(180deg, #0c1430 0%, #05070f 100%);
  box-shadow:
      inset 0 0 0 1.5px rgba(56, 189, 248, 0.35),
      inset 0 0 30px rgba(59, 130, 246, 0.18);
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

/* Fio dourado interno, deslocado da moldura */
.fbtn__inline {
  --cut: 10px;

  position: absolute;
  inset: 7px;
  background: linear-gradient(150deg, rgba(255, 236, 176, 0.95), rgba(190, 140, 40, 0.75), rgba(255, 236, 176, 0.95));
  pointer-events: none;
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

.fbtn__inline::after {
  --cut: 9px;

  content: '';
  position: absolute;
  inset: 1.5px;
  background: linear-gradient(180deg, #0b1329 0%, #05070f 100%);
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

.fbtn__sheen {
  position: absolute;
  top: 0;
  left: -60%;
  width: 45%;
  height: 100%;
  background: linear-gradient(100deg, transparent, rgba(255, 240, 190, 0.16), transparent);
  transform: skewX(-18deg);
  pointer-events: none;
}

.fbtn__content {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  min-width: 0;
}

.fbtn__icon {
  flex: 0 0 auto;
  font-size: 1.6rem;
  background: linear-gradient(180deg, #fff3c4 0%, #f2c34a 45%, #c8901a 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  filter: drop-shadow(0 2px 2px rgba(0, 0, 0, 0.6));
}

.fbtn__label {
  min-width: 0;
  font-family: var(--font-display, 'Rajdhani', sans-serif);
  font-size: 1.35rem;
  font-style: italic;
  font-weight: 700;
  letter-spacing: 0.06em;
  line-height: 1.1;
  text-transform: uppercase;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  background: linear-gradient(180deg, #fff6d5 0%, #f5cc5c 40%, #c9901c 62%, #ffe9a8 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  filter: drop-shadow(0 2px 0 rgba(0, 0, 0, 0.55)) drop-shadow(0 0 10px rgba(240, 198, 92, 0.35));
}

.fbtn:hover:not(:disabled) {
  filter: drop-shadow(0 0 16px rgba(240, 198, 92, 0.4)) drop-shadow(0 8px 18px rgba(0, 0, 0, 0.5));
}

.fbtn:hover:not(:disabled) .fbtn__sheen {
  animation: fbtnSheen 0.9s ease-out;
}

.fbtn:active:not(:disabled) {
  transform: scale(0.985);
}

.fbtn:focus-visible {
  outline: 2px solid rgba(125, 211, 252, 0.9);
  outline-offset: 3px;
}

.fbtn:disabled {
  cursor: not-allowed;
  filter: grayscale(0.35) brightness(0.75);
  opacity: 0.7;
}

@keyframes fbtnSheen {
  0% {
    left: -60%;
  }

  100% {
    left: 115%;
  }
}

@media (min-width: 640px) {
  .fbtn {
    --cut: 20px;
  }

  .fbtn__inner {
    --cut: 16px;
    min-height: 64px;
  }

  .fbtn__label {
    font-size: 1.7rem;
    letter-spacing: 0.07em;
  }

  .fbtn__icon {
    font-size: 2rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .fbtn:hover:not(:disabled) .fbtn__sheen {
    animation: none;
  }
}
</style>

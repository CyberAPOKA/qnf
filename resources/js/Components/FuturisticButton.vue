<script setup>
defineProps({
  label: {
    type: String,
    default: 'CANCEL'
  }
});

const emit = defineEmits(['click']);
</script>

<template>
  <button class="futuristic-button" @click="emit('click')">
    <div class="button-inner">
      <!-- Camada de brilho interno (inset glow) -->
      <div class="inner-glow"></div>
      <span class="label">{{ label }}</span>
    </div>
    <!-- Detalhe amarelo no canto superior esquerdo -->
    <div class="corner-accent"></div>
  </button>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@700&display=swap');

.futuristic-button {
  position: relative;
  background-color: #D2B06A;
  padding: 1px;
  cursor: pointer;
  min-width: 320px;
  height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  border: none;
  outline: none;
  clip-path: polygon(12px 0,
      100% 0,
      100% calc(100% - 12px),
      calc(100% - 12px) 100%,
      0 100%,
      0 12px);
}

.button-inner {
  position: relative;
  width: 100%;
  height: 100%;
  background-color: #000000;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  z-index: 2;
  overflow: hidden;
  border-top-right-radius: 4px;
  border-bottom-left-radius: 4px;
  clip-path: polygon(11px 0,
      100% 0,
      100% calc(100% - 11px),
      calc(100% - 11px) 100%,
      0 100%,
      0 11px);
}

.inner-glow {
  position: absolute;
  inset: 0;
  pointer-events: none;
  transition: all 0.3s ease;
  opacity: 0;
  z-index: 1;
  box-shadow: inset 0 0 20px 5px #D2B06A;
}

.label {
  color: #D2B06A;
  font-family: 'Inter', sans-serif;
  font-weight: 700;
  font-size: 16px;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  z-index: 3;
  transition: all 0.3s ease;
}

.corner-accent {
  position: absolute;
  top: 0;
  left: 0;
  width: 24px;
  height: 24px;
  background-color: #D2B06A;
  z-index: 4;
  clip-path: polygon(0 0, 100% 0, 0 100%);
  transition: all 0.3s ease;
}

.futuristic-button:hover {
  background-color: #f0d9a0;
}

.futuristic-button:hover .inner-glow {
  opacity: 1;
  box-shadow: inset 0 0 10px 3px #D2B06A;
}

.futuristic-button:hover .label {
  text-shadow: 0 0 12px rgba(210, 176, 106, 0.9);
}

.futuristic-button:active {
  transform: scale(0.98);
  transition: all 0.1s ease;
}
</style>
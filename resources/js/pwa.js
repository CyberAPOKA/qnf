import { registerSW } from 'virtual:pwa-register';

const updateSW = registerSW({
    immediate: true,
    onNeedRefresh() {
        const shouldUpdate = window.confirm(
            'Uma nova versão do QNF está disponível. Atualizar agora?'
        );

        if (shouldUpdate) {
            updateSW(true);
        }
    },
});
import { registerSW } from 'virtual:pwa-register';

const CHECK_INTERVAL_MS = 60 * 1000;

registerSW({
    immediate: true,
    onRegisteredSW(_swUrl, registration) {
        if (!registration) {
            return;
        }

        // Procura nova versão periodicamente (usuário com app aberto)
        setInterval(() => {
            registration.update();
        }, CHECK_INTERVAL_MS);
    },
});

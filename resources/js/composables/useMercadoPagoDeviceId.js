import { onMounted, onUnmounted, ref } from 'vue';

const SCRIPT_SRC = 'https://www.mercadopago.com/v2/security.js';

export function useMercadoPagoDeviceId() {
    const deviceId = ref(null);
    const ready = ref(false);

    let timer = null;

    const readDeviceId = () => {
        const value = typeof window !== 'undefined' ? window.MP_DEVICE_SESSION_ID : null;

        if (typeof value === 'string' && value.length >= 8) {
            deviceId.value = value;
        }
    };

    onMounted(() => {
        if (typeof document === 'undefined') {
            ready.value = true;
            return;
        }

        if (!document.querySelector(`script[src="${SCRIPT_SRC}"]`)) {
            const script = document.createElement('script');
            script.src = SCRIPT_SRC;
            script.setAttribute('view', 'checkout');
            document.head.appendChild(script);
        }

        const startedAt = Date.now();

        timer = window.setInterval(() => {
            readDeviceId();

            if (deviceId.value || Date.now() - startedAt > 4000) {
                ready.value = true;
                window.clearInterval(timer);
                timer = null;
            }
        }, 100);
    });

    onUnmounted(() => {
        if (timer) {
            window.clearInterval(timer);
        }
    });

    return { deviceId, ready };
}

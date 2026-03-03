import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

export function useCountdown(getTargetDate) {
    const countdown = ref('');
    let timer = null;

    const update = () => {
        const target = getTargetDate();
        if (!target) { countdown.value = ''; return; }

        const diff = new Date(target) - Date.now();
        if (diff <= 0) {
            countdown.value = '';
            clearInterval(timer);
            router.reload();
            return;
        }

        const days = Math.floor(diff / 86400000);
        const hours = Math.floor((diff % 86400000) / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);

        const parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (hours > 0) parts.push(`${hours}h`);
        if (minutes > 0) parts.push(`${String(minutes).padStart(2, '0')}m`);
        if (minutes > 0 || hours > 0 || days > 0) {
            parts.push(`${String(seconds).padStart(2, '0')}s`);
        } else {
            parts.push(`${seconds}`);
        }
        countdown.value = parts.join(' ');
    };

    onMounted(() => {
        update();
        timer = setInterval(update, 1000);
    });

    onUnmounted(() => {
        clearInterval(timer);
    });

    return { countdown };
}

import { onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useGameStore } from '@/stores/gameStore';

export function useDraftCountdown() {
    const store = useGameStore();
    const countdown = ref(null);
    const isCountingDown = ref(false);

    let timer = null;

    const clear = () => {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        isCountingDown.value = false;
        countdown.value = null;
    };

    const start = () => {
        clear();
        countdown.value = 10;
        isCountingDown.value = true;

        timer = setInterval(() => {
            countdown.value--;
            if (countdown.value <= 0) {
                clear();
                if (store.game?.id) {
                    router.visit(route('games.draft', store.game.id));
                }
            }
        }, 1000);
    };

    watch(
        () => store.game?.status,
        (status, oldStatus) => {
            if (status === 'drafting' && oldStatus !== 'drafting' && oldStatus !== undefined) {
                start();
            } else if (status !== 'drafting') {
                clear();
            }
        }
    );

    onBeforeUnmount(() => {
        clear();
    });

    return { countdown, isCountingDown };
}

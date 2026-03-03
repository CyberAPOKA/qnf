import { watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useGameStore } from '@/stores/gameStore';

export function useDraftRedirect() {
    const store = useGameStore();

    watch(
        () => store.game?.status,
        (status, oldStatus) => {
            if (status === 'drafting' && oldStatus !== 'drafting' && oldStatus !== undefined) {
                if (store.game?.id) {
                    router.visit(route('games.draft', store.game.id));
                }
            }
        }
    );
}

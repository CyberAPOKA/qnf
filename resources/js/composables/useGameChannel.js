import { onBeforeUnmount, onMounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useGameStore } from '@/stores/gameStore';

const ALL_EVENTS = [
    '.GamePlayerJoined',
    '.GameBecameFull',
    '.CaptainsDrawn',
    '.DraftPickMade',
    '.DraftTurnChanged',
    '.DraftFinished',
];

const RELOAD_EVENTS = new Set([
    '.DraftPickMade',
    '.DraftTurnChanged',
    '.DraftFinished',
]);

export function useGameChannel(props) {
    const store = useGameStore();

    const handleEvent = (payload, eventName) => {
        store.patchFromEvent(payload);

        if (RELOAD_EVENTS.has(eventName)) {
            router.reload({ preserveScroll: true, preserveState: true });
        }
    };

    onMounted(() => {
        store.hydrate(props.game);

        if (!store.channelName || !window.Echo) return;

        const channel = window.Echo.private(store.channelName);
        ALL_EVENTS.forEach((event) => {
            channel.listen(event, (data) => handleEvent(data, event));
        });
    });

    watch(
        () => props.game,
        (game) => {
            store.hydrate(game);
        }
    );

    onBeforeUnmount(() => {
        if (store.channelName && window.Echo) {
            window.Echo.leave(`private-${store.channelName}`);
        }
    });

    return { store };
}

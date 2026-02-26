import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useGameStore } from '@/stores/gameStore';

const ALL_EVENTS = [
    '.GamePlayerJoined',
    '.GameBecameFull',
    '.CaptainsDrawn',
    '.DraftPickMade',
    '.DraftTurnChanged',
    '.DraftFinished',
];

export function useGameChannel(props) {
    const store = useGameStore();

    const handleEvent = (payload) => {
        store.patchFromEvent(payload);
    };

    onMounted(() => {
        store.hydrate(props.game);

        if (!store.channelName || !window.Echo) return;

        const channel = window.Echo.private(store.channelName);
        ALL_EVENTS.forEach((event) => channel.listen(event, handleEvent));
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

import { defineStore } from 'pinia'

export const useGameStore = defineStore('game', {
    state: () => ({
        game: null,
        channelName: null,
    }),
    getters: {
        isFull: (state) => state.game?.status === 'full',
    },
    actions: {
        hydrate(game) {
            this.game = game
            this.channelName = game ? `game.${game.id}` : null
        },
        patchFromEvent(payload) {
            if (payload?.game) {
                this.game = payload.game
                this.channelName = payload.game ? `game.${payload.game.id}` : null
                return
            }

            if (payload?.id) {
                this.game = payload
                this.channelName = `game.${payload.id}`
            }
        },
    },
})

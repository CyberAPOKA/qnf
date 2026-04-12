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
            if (!payload) return

            // Laravel envelopa propriedades públicas do evento em { game: {...} }.
            // Se vier um pick payload dentro de .game, desembrulha.
            if (payload.game && payload.game.picked_user_id != null) {
                payload = payload.game
            }

            // Pick made: patch localmente sem reload
            if (payload.picked_user_id != null && this.game) {
                const current = this.game
                const next = { ...current }

                next.available_players = (current.available_players || [])
                    .filter((p) => p.id !== payload.picked_user_id)

                if (payload.teams) next.teams = payload.teams
                if ('turn_color' in payload) next.turn_color = payload.turn_color
                if ('is_double_pick' in payload) next.is_double_pick = payload.is_double_pick
                if (payload.status) next.status = payload.status

                if (payload.picks_count != null) {
                    const currentPicks = current.picks || []
                    if (currentPicks.length < payload.picks_count) {
                        const diff = payload.picks_count - currentPicks.length
                        next.picks = [
                            ...currentPicks,
                            ...Array.from({ length: diff }, (_, i) => ({
                                id: `stub-${currentPicks.length + i}`,
                            })),
                        ]
                    } else {
                        next.picks = currentPicks.slice(0, payload.picks_count)
                    }
                }

                this.game = next
                return
            }

            if (payload._slim) {
                this.game = { ...this.game, ...payload }
                return
            }

            if (payload.game) {
                this.game = payload.game
                this.channelName = payload.game ? `game.${payload.game.id}` : null
                return
            }

            if (payload.id) {
                this.game = payload
                this.channelName = `game.${payload.id}`
            }
        },
    },
})

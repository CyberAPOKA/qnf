<?php

namespace App\Instagram\Services;

use App\Instagram\Support\InstagramUsernameNormalizer;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

class ScoringInstagramUsersResolver
{
    /**
     * @return array{
     *     tagged: list<string>,
     *     ignored: list<array{user_id: int, name: string, reason: string}>,
     *     rejected: list<array{user_id: int, name: string, username: ?string, reason: string}>
     * }
     */
    public function resolveForGame(Game $game): array
    {
        $players = $this->scoringPlayers($game);

        return $this->classify($players);
    }

    /**
     * @return array{
     *     tagged: list<string>,
     *     ignored: list<array{user_id: int, name: string, reason: string}>,
     *     rejected: list<array{user_id: int, name: string, username: ?string, reason: string}>
     * }
     */
    public function resolveForTeamColor(Game $game, string $color): array
    {
        $teamUserIds = $this->teamUserIds($game, $color);

        if ($teamUserIds->isEmpty()) {
            return [
                'tagged' => [],
                'ignored' => [],
                'rejected' => [],
            ];
        }

        $players = $this->scoringPlayers($game)
            ->filter(fn (User $user) => $teamUserIds->contains($user->id))
            ->values();

        return $this->classify($players);
    }

    /**
     * @return Collection<int, User>
     */
    private function scoringPlayers(Game $game): Collection
    {
        $userIds = GamePlayer::query()
            ->where('game_id', $game->id)
            ->where('points', '>', 0)
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'instagram_username'])
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function teamUserIds(Game $game, string $color): Collection
    {
        $ids = collect();

        $team = Team::query()
            ->where('game_id', $game->id)
            ->where('color', $color)
            ->first();

        if ($team?->captain_user_id) {
            $ids->push((int) $team->captain_user_id);
        }

        $picked = DraftPick::query()
            ->where('game_id', $game->id)
            ->where('team_color', $color)
            ->pluck('picked_user_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        return $ids->merge($picked)->unique()->values();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{
     *     tagged: list<string>,
     *     ignored: list<array{user_id: int, name: string, reason: string}>,
     *     rejected: list<array{user_id: int, name: string, username: ?string, reason: string}>
     * }
     */
    private function classify(Collection $users): array
    {
        $ownUsername = InstagramUsernameNormalizer::tryNormalize(
            (string) config('instagram.own_username', 'qnfporto')
        );
        $limit = max(0, (int) config('instagram.limits.user_tags', 20));

        $tagged = [];
        $ignored = [];
        $rejected = [];
        $seen = [];

        foreach ($users as $user) {
            $raw = $user->instagram_username;

            if ($raw === null || trim((string) $raw) === '') {
                $ignored[] = [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'reason' => 'missing_username',
                ];

                continue;
            }

            $normalized = InstagramUsernameNormalizer::tryNormalize((string) $raw);

            if ($normalized === null) {
                $rejected[] = [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => (string) $raw,
                    'reason' => 'invalid_username',
                ];

                continue;
            }

            if ($ownUsername !== null && $normalized === $ownUsername) {
                $rejected[] = [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => $normalized,
                    'reason' => 'own_username',
                ];

                continue;
            }

            if (isset($seen[$normalized])) {
                $rejected[] = [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => $normalized,
                    'reason' => 'duplicate',
                ];

                continue;
            }

            if (count($tagged) >= $limit) {
                $rejected[] = [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => $normalized,
                    'reason' => 'tag_limit',
                ];

                continue;
            }

            $seen[$normalized] = true;
            $tagged[] = $normalized;
        }

        return [
            'tagged' => $tagged,
            'ignored' => $ignored,
            'rejected' => $rejected,
        ];
    }
}

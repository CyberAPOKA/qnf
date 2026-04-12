<?php

namespace Database\Seeders;

use App\Models\DraftPick;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MergeDuplicatePlayersSeeder extends Seeder
{
    /**
     * Mapa de merges: [id_antigo => id_destino (último criado)]
     */
    protected array $merges = [
        38 => 42,
        39 => 49,
        44 => 48,
        45 => 48,
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->merges as $fromId => $toId) {
                $this->mergeUser($fromId, $toId);
            }
        });

        $this->command->info('Merge de jogadores duplicados concluído.');
    }

    protected function mergeUser(int $fromId, int $toId): void
    {
        $from = User::find($fromId);
        $to   = User::find($toId);

        if (! $from) {
            $this->command->warn("Usuário origem {$fromId} não existe, pulando.");
            return;
        }

        if (! $to) {
            $this->command->warn("Usuário destino {$toId} não existe, pulando.");
            return;
        }

        $this->command->info("Mesclando user {$fromId} ({$from->name}) -> {$toId} ({$to->name})");

        // 1. GamePlayer: respeitar unique(game_id, user_id)
        $fromGamePlayers = GamePlayer::where('user_id', $fromId)->get();

        foreach ($fromGamePlayers as $gp) {
            $existing = GamePlayer::where('game_id', $gp->game_id)
                ->where('user_id', $toId)
                ->first();

            if ($existing) {
                // Destino já está no jogo: soma pontos e remove duplicado
                $existing->update([
                    'points'      => $existing->points + $gp->points,
                    'joined_at'   => $existing->joined_at ?? $gp->joined_at,
                    'waitlist_at' => $existing->waitlist_at ?? $gp->waitlist_at,
                    'dropped_out' => $existing->dropped_out && $gp->dropped_out,
                ]);
                $gp->delete();
            } else {
                $gp->update(['user_id' => $toId]);
            }
        }

        // 2. Teams: captain e first_pick
        Team::where('captain_user_id', $fromId)->update(['captain_user_id' => $toId]);
        Team::where('first_pick_user_id', $fromId)->update(['first_pick_user_id' => $toId]);

        // 3. DraftPicks
        DraftPick::where('picked_user_id', $fromId)->update(['picked_user_id' => $toId]);

        // 4. Remover usuário antigo
        $from->delete();

        $this->command->info("  ✓ User {$fromId} removido.");
    }
}

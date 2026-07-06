<?php

namespace App\Console\Commands;

use App\Enums\GameStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\WeekTeamMusicService;
use Illuminate\Console\Command;

class BackfillCaptainWeekTeamMusicCommand extends Command
{
    protected $signature = 'futsal:backfill-captain-music
        {--user= : ID de um jogador específico}
        {--force : Sobrescreve snapshots já existentes}';

    protected $description = 'Preenche a música do time da semana nas rodadas em que cada jogador foi capitão vencedor';

    public function handle(WeekTeamMusicService $musicService): int
    {
        $force = (bool) $this->option('force');
        $userId = $this->option('user');

        $usersQuery = User::query()
            ->where(function ($query) {
                $query->whereNotNull('music_youtube_id')
                    ->orWhereNotNull('music_file_path');
            });

        if ($userId) {
            $usersQuery->whereKey($userId);
        }

        $users = $usersQuery->orderBy('name')->get();

        if ($users->isEmpty()) {
            $this->warn('Nenhum jogador com música encontrado.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $this->info("Processando {$users->count()} jogador(es) com música...");

        foreach ($users as $user) {
            if (! $musicService->userHasMusic($user)) {
                continue;
            }

            $teams = Team::query()
                ->where('captain_user_id', $user->id)
                ->whereHas('game', function ($query) {
                    $query->where('status', GameStatus::DONE)
                        ->whereNotNull('week_team_images');
                })
                ->with('game')
                ->get()
                ->filter(fn (Team $team) => $musicService->isWinningTeamInGame($team));

            if ($teams->isEmpty()) {
                $this->line("  {$user->name}: nenhuma rodada como capitão vencedor");

                continue;
            }

            $this->line("  {$user->name}: {$teams->count()} rodada(s)");

            foreach ($teams as $team) {
                $game = $team->game;
                $sortOrder = $musicService->resolveSortOrderForColor($game, $team->color);

                $result = $musicService->upsertCaptainSnapshot(
                    $game,
                    $team->color,
                    $user,
                    $sortOrder,
                    $force,
                );

                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                };
            }
        }

        $this->newLine();
        $this->info("Concluído: {$created} criado(s), {$updated} atualizado(s), {$skipped} ignorado(s).");

        return self::SUCCESS;
    }
}

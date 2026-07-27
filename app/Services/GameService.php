<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Events\CaptainsDrawn;
use App\Events\GameBecameFull;
use App\Models\Game;
use App\Models\User;
use App\Support\GamePayload;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class GameService
{
    public const TZ = 'America/Sao_Paulo';

    /** Week team remains visible until this weekday/hour; then the next round is created. */
    public const NEXT_ROUND_WEEKDAY = CarbonInterface::THURSDAY;

    public const NEXT_ROUND_HOUR = 12;

    public function getOrCreateThisWeekGame(?User $admin = null, ?CarbonInterface $now = null): Game
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);
        $gameDate = $this->resolveGameDate($clock);
        $opensAt = $this->resolveOpensAt($gameDate);

        // Se já existe um jogo não finalizado, retorna ele (evita criar rodada duplicada)
        $activeGame = Game::where('status', '!=', GameStatus::DONE)
            ->orderByDesc('date')
            ->first();

        if ($activeGame) {
            return $activeGame;
        }

        $existingGame = Game::whereDate('date', $gameDate->toDateString())
            ->where('status', '!=', GameStatus::DONE)
            ->first();

        if ($existingGame) {
            return $existingGame;
        }

        if (Game::whereDate('date', $gameDate->toDateString())->where('status', GameStatus::DONE)->exists()) {
            $gameDate = $gameDate->addWeek();
            $opensAt = $this->resolveOpensAt($gameDate);

            $existingGame = Game::whereDate('date', $gameDate->toDateString())
                ->where('status', '!=', GameStatus::DONE)
                ->first();

            if ($existingGame) {
                return $existingGame;
            }
        }

        // Nova rodada só a partir de quinta 12h (e sex–dom). Até lá mantém o jogo DONE
        // com times da semana visíveis — não criar só porque o placar foi registrado.
        if ($this->canCreateNextRound($clock)) {
            $lastRound = Game::whereYear('date', $gameDate->year)->max('round') ?? 0;

            return Game::create([
                'date' => $gameDate->toDateString(),
                'opens_at' => $opensAt,
                'round' => $lastRound + 1,
                'status' => GameStatus::SCHEDULED,
                'created_by' => $admin?->id,
            ]);
        }

        // Seg–qui de manhã (rodada finalizada): mantém resultados / time da semana
        return Game::orderByDesc('date')->firstOrFail();
    }

    public function openGameIfNeeded(?CarbonInterface $now = null): ?Game
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);
        $game = $this->getOrCreateThisWeekGame(null, $clock);

        if ($game->status !== GameStatus::SCHEDULED) {
            return null;
        }

        if ($clock->greaterThanOrEqualTo($game->opens_at->setTimezone(self::TZ))) {
            $game->status = GameStatus::OPEN;
            $game->save();

            return $game;
        }

        return null;
    }

    public function forceOpenThisWeekGame(?User $admin = null, ?CarbonInterface $now = null): Game
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);
        $game = $this->getOrCreateThisWeekGame($admin, $clock);

        if ($game->status === GameStatus::SCHEDULED) {
            $game->status = GameStatus::OPEN;
            $game->save();
        }

        return $game;
    }

    public function handleGameBecameFull(Game $game, DraftService $draftService): void
    {
        $game->update(['closes_at' => now()]);

        try {
            $draftService->drawCaptains($game);
        } catch (ValidationException) {
            $payload = GamePayload::fromGame($game->refresh(), $draftService);
            rescue(fn () => broadcast(new GameBecameFull($game->id, $payload))->toOthers(), report: false);

            return;
        }

        $freshGame = Game::findOrFail($game->id);
        $payload = GamePayload::fromGame($freshGame, $draftService);

        rescue(fn () => broadcast(new GameBecameFull($freshGame->id, $payload))->toOthers(), report: false);
        rescue(fn () => broadcast(new CaptainsDrawn($freshGame->id, $payload))->toOthers(), report: false);
    }

    /**
     * Nova rodada: quinta a partir das 12h, ou sexta–domingo.
     */
    public function canCreateNextRound(CarbonInterface $clock): bool
    {
        $base = CarbonImmutable::instance($clock)->setTimezone(self::TZ);

        if ($base->isFriday() || $base->isSaturday() || $base->isSunday()) {
            return true;
        }

        if ($base->isThursday() && $base->hour >= self::NEXT_ROUND_HOUR) {
            return true;
        }

        return false;
    }

    /**
     * Inscrições abrem na sexta 17h anterior à segunda do jogo.
     */
    public function resolveOpensAt(CarbonImmutable $gameMonday): CarbonImmutable
    {
        return $gameMonday->subDays(3)->setTime(17, 0);
    }

    private function resolveGameDate(CarbonInterface $date): CarbonImmutable
    {
        $base = CarbonImmutable::instance($date)->setTimezone(self::TZ);
        $thisMonday = $this->thisWeekMondayDate($base);

        // Qui 12h+ e sex–dom → próxima segunda
        if ($this->canCreateNextRound($base)) {
            if ($base->isFriday() || $base->isSaturday() || $base->isSunday()) {
                return $thisMonday->addWeek();
            }

            // Quinta após 12h: ainda na semana do jogo desta segunda (já DONE) → próxima segunda
            return $thisMonday->addWeek();
        }

        return $thisMonday;
    }

    public function thisWeekMondayDate(CarbonInterface $date): CarbonImmutable
    {
        $base = CarbonImmutable::instance($date)->setTimezone(self::TZ);

        return $base->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }
}

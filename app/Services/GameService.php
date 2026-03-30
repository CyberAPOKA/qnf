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

    public function getOrCreateThisWeekGame(?User $admin = null, ?CarbonInterface $now = null): Game
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);
        $gameDate = $this->resolveGameDate($clock);
        $opensAt = $gameDate->subDay()->setTime(17, 0);

        $existingGame = Game::whereDate('date', $gameDate->toDateString())->first();

        if ($existingGame) {
            return $existingGame;
        }

        $lastRound = Game::whereYear('date', $gameDate->year)->max('round') ?? 0;

        return Game::create([
            'date' => $gameDate->toDateString(),
            'opens_at' => $opensAt,
            'round' => $lastRound + 1,
            'status' => GameStatus::SCHEDULED,
            'created_by' => $admin?->id,
        ]);
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

    private function resolveGameDate(CarbonInterface $date): CarbonImmutable
    {
        $base = CarbonImmutable::instance($date)->setTimezone(self::TZ);
        $thisMonday = $this->thisWeekMondayDate($base);

        if ($base->isSaturday() || $base->isSunday()) {
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
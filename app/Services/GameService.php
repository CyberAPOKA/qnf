<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Events\CaptainsDrawn;
use App\Events\GameBecameFull;
use App\Models\Game;
use App\Models\GameSchedule;
use App\Models\User;
use App\Support\GamePayload;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class GameService
{
    public const TZ = 'America/Sao_Paulo';

    public const NEXT_ROUND_WEEKDAY = CarbonInterface::THURSDAY;

    public const NEXT_ROUND_HOUR = 12;

    public const MARKET_OPENS_WEEKDAY = CarbonInterface::FRIDAY;

    public const MARKET_OPENS_HOUR = 17;

    public const GAME_WEEKDAY = CarbonInterface::MONDAY;

    public const GAME_HOUR = 21;

    public function getOrCreateThisWeekGame(?User $admin = null, ?CarbonInterface $now = null): Game
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);

        $this->createScheduledGameIfNeeded($admin, $clock);

        $activeGame = Game::where('status', '!=', GameStatus::DONE)
            ->orderByDesc('date')
            ->first();

        if ($activeGame) {
            return $activeGame;
        }

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
        $this->createScheduledGameIfNeeded($admin, $clock, force: true);
        $game = $this->getOrCreateThisWeekGame($admin, $clock);

        if ($game->status === GameStatus::SCHEDULED) {
            $game->status = GameStatus::OPEN;
            $game->save();
        }

        return $game;
    }

    public function createScheduledGameIfNeeded(?User $admin = null, ?CarbonInterface $now = null, bool $force = false): ?Game
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);

        if (Game::where('status', '!=', GameStatus::DONE)->exists()) {
            return null;
        }

        $schedule = GameSchedule::query()
            ->whereNull('game_id')
            ->orderBy('creates_at')
            ->first();

        if (! $schedule) {
            return null;
        }

        $createsAt = $schedule->creates_at->timezone(self::TZ);

        if (! $force && $clock->lt($createsAt)) {
            return null;
        }

        $gameDate = $schedule->starts_at->timezone(self::TZ)->toDateString();

        if (Game::whereDate('date', $gameDate)->exists()) {
            return null;
        }

        $game = Game::create([
            'date' => $gameDate,
            'starts_at' => $schedule->starts_at,
            'opens_at' => $schedule->opens_at,
            'round' => $this->nextRound(),
            'status' => GameStatus::SCHEDULED,
            'created_by' => $admin?->id ?? $schedule->created_by,
        ]);

        $schedule->update(['game_id' => $game->id]);

        return $game;
    }

    public function saveSchedule(array $data, ?User $admin = null): GameSchedule
    {
        $schedule = GameSchedule::query()->whereNull('game_id')->first();

        $payload = [
            'round' => $this->nextRound(),
            'creates_at' => $data['creates_at'],
            'opens_at' => $data['opens_at'],
            'starts_at' => $data['starts_at'],
            'created_by' => $admin?->id,
        ];

        if ($schedule) {
            $schedule->update($payload);

            return $schedule->refresh();
        }

        return GameSchedule::create($payload);
    }

    /**
     * @return array{round: int, creates_at: CarbonImmutable, opens_at: CarbonImmutable, starts_at: CarbonImmutable}
     */
    public function defaultSchedule(?CarbonInterface $now = null): array
    {
        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);
        $thursday = $clock->startOfWeek(CarbonInterface::MONDAY)->addDays(3);
        $createsAt = $thursday->setTime(self::NEXT_ROUND_HOUR, 0);

        if ($clock->gte($createsAt)) {
            $thursday = $thursday->addWeek();
            $createsAt = $thursday->setTime(self::NEXT_ROUND_HOUR, 0);
        }

        $opensAt = $thursday->next(self::MARKET_OPENS_WEEKDAY)->setTime(self::MARKET_OPENS_HOUR, 0);
        $startsAt = $thursday->next(CarbonInterface::MONDAY)->setTime(self::GAME_HOUR, 0);

        return [
            'round' => $this->nextRound(),
            'creates_at' => $createsAt,
            'opens_at' => $opensAt,
            'starts_at' => $startsAt,
        ];
    }

    public function nextRound(): int
    {
        return (int) (Game::max('round') ?? 0) + 1;
    }

    public function pendingSchedule(): ?GameSchedule
    {
        return GameSchedule::query()->whereNull('game_id')->orderBy('creates_at')->first();
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
     * Mercado abre na sexta 17h anterior à segunda do jogo.
     */
    public function resolveOpensAt(CarbonImmutable $gameMonday): CarbonImmutable
    {
        return $gameMonday->subDays(3)->setTime(self::MARKET_OPENS_HOUR, 0);
    }

    public function thisWeekMondayDate(CarbonInterface $date): CarbonImmutable
    {
        $base = CarbonImmutable::instance($date)->setTimezone(self::TZ);

        return $base->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }
}

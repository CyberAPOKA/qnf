<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipationOutcome;
use App\Enums\Position;
use App\Events\GamePlayerJoined;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Support\GamePayload;
use App\Support\ParticipationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameParticipationService
{
    public function __construct(
        private readonly DraftService $draftService,
        private readonly GameService $gameService,
        private readonly WaitlistService $waitlistService,
        private readonly PaymentService $paymentService,
    ) {}

    public function join(Game $game, User $user): ParticipationResult
    {
        $result = DB::transaction(function () use ($game, $user): ParticipationResult {
            $lockedGame = $this->lockGame($game);

            if ($lockedGame->status !== GameStatus::OPEN) {
                throw ValidationException::withMessages(['join' => 'A lista não está aberta.']);
            }

            return $this->enrollAsLinePlayer($lockedGame, $user);
        });

        $this->afterMainListChange($game, $result);

        return $result;
    }

    public function joinWaitlist(Game $game, User $user): ParticipationResult
    {
        return DB::transaction(function () use ($game, $user): ParticipationResult {
            $lockedGame = $this->lockGame($game);

            if (! in_array($lockedGame->status, [GameStatus::FULL, GameStatus::DRAFTING, GameStatus::DRAFTED], true)) {
                throw ValidationException::withMessages(['waitlist' => 'A fila de espera não está disponível.']);
            }

            $this->assertCanEnterWaitlist($lockedGame, $user);

            $existing = $this->findParticipation($lockedGame, $user);

            if ($existing) {
                if ($existing->dropped_out) {
                    throw ValidationException::withMessages(['waitlist' => 'Você desistiu e não pode entrar na fila.']);
                }

                throw ValidationException::withMessages(['waitlist' => 'Você já está inscrito ou na fila.']);
            }

            return $this->createWaitlistEntry($lockedGame, $user);
        });
    }

    public function joinOrWaitlist(Game $game, User $user): ParticipationResult
    {
        $result = DB::transaction(function () use ($game, $user): ParticipationResult {
            $lockedGame = $this->lockGame($game);

            $existing = $this->findParticipation($lockedGame, $user);

            if ($existing && ! $existing->dropped_out) {
                return $this->alreadyParticipating($lockedGame, $existing);
            }

            if ($lockedGame->status === GameStatus::OPEN) {
                return $this->enrollAsLinePlayer($lockedGame, $user, $existing);
            }

            if (in_array($lockedGame->status, [GameStatus::FULL, GameStatus::DRAFTING, GameStatus::DRAFTED], true)) {
                $this->assertCanEnterWaitlist($lockedGame, $user, $existing);

                return $this->createWaitlistEntry($lockedGame, $user);
            }

            throw ValidationException::withMessages(['join' => 'A partida não está disponível para inscrição.']);
        });

        $this->afterMainListChange($game, $result);

        return $result;
    }

    public function quit(Game $game, User $user): ParticipationResult
    {
        $result = DB::transaction(function () use ($game, $user): ParticipationResult {
            $lockedGame = $this->lockGame($game);

            if (! in_array($lockedGame->status, [GameStatus::OPEN, GameStatus::FULL, GameStatus::DRAFTED], true)) {
                throw ValidationException::withMessages(['quit' => 'Não é possível desistir neste momento.']);
            }

            $gamePlayer = $this->findActiveParticipation($lockedGame, $user);

            if (! $gamePlayer) {
                throw ValidationException::withMessages(['quit' => 'Você não está inscrito nesta partida.']);
            }

            return $this->dropPlayer($lockedGame, $gamePlayer);
        });

        $this->afterDrop($game, $user);

        return $result;
    }

    public function removePlayer(Game $game, User $user): ParticipationResult
    {
        $result = DB::transaction(function () use ($game, $user): ParticipationResult {
            $lockedGame = $this->lockGame($game);

            if (! in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN, GameStatus::FULL, GameStatus::DRAFTING, GameStatus::DRAFTED], true)) {
                throw ValidationException::withMessages(['remove' => 'Não é possível remover jogadores neste momento.']);
            }

            $gamePlayer = $this->findActiveParticipation($lockedGame, $user);

            if (! $gamePlayer) {
                throw ValidationException::withMessages(['remove' => 'Jogador não encontrado na partida ou na fila.']);
            }

            $dropped = $this->dropPlayer($lockedGame, $gamePlayer);

            return new ParticipationResult(
                outcome: $dropped->outcome === ParticipationOutcome::LeftWaitlist
                    ? ParticipationOutcome::RemovedFromWaitlist
                    : ParticipationOutcome::Removed,
                promoted: $dropped->promoted,
                target: $user,
            );
        });

        $this->afterDrop($game, $user);

        return $result;
    }

    private function lockGame(Game $game): Game
    {
        return Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
    }

    private function enrollAsLinePlayer(Game $game, User $user, ?GamePlayer $existing = null): ParticipationResult
    {
        if ($user->isSuspended((int) ($game->round ?? 0))) {
            throw ValidationException::withMessages(['join' => 'Você está suspenso e não pode se inscrever.']);
        }

        if ($user->position === Position::GOALKEEPER) {
            throw ValidationException::withMessages(['join' => 'Goleiros são adicionados pelo administrador.']);
        }

        $existing ??= $this->findParticipation($game, $user);

        if ($existing) {
            if ($existing->dropped_out) {
                throw ValidationException::withMessages(['join' => 'Você desistiu e não pode se inscrever novamente.']);
            }

            return $this->alreadyParticipating($game, $existing);
        }

        $linePlayerCount = GamePlayer::where('game_id', $game->id)
            ->where('dropped_out', false)
            ->whereNull('waitlist_at')
            ->whereHas('user', fn ($q) => $q->where('position', '!=', Position::GOALKEEPER))
            ->count();

        if ($linePlayerCount >= 12) {
            throw ValidationException::withMessages(['join' => 'As vagas para jogadores de linha estão esgotadas.']);
        }

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'joined_at' => now(),
        ]);

        $countAfter = GamePlayer::where('game_id', $game->id)
            ->where('dropped_out', false)
            ->whereNull('waitlist_at')
            ->count();

        if ($countAfter >= 15) {
            $game->update(['status' => GameStatus::FULL]);
        }

        return new ParticipationResult(
            outcome: ParticipationOutcome::Joined,
            target: $user,
        );
    }

    private function assertCanEnterWaitlist(Game $game, User $user, ?GamePlayer $existing = null): void
    {
        if ($user->isSuspended((int) ($game->round ?? 0))) {
            throw ValidationException::withMessages(['waitlist' => 'Você está suspenso e não pode entrar na fila.']);
        }

        if ($user->position === Position::GOALKEEPER) {
            throw ValidationException::withMessages(['waitlist' => 'Goleiros não podem entrar na fila de espera.']);
        }

        $existing ??= $this->findParticipation($game, $user);

        if ($existing?->dropped_out) {
            throw ValidationException::withMessages(['waitlist' => 'Você desistiu e não pode entrar na fila.']);
        }
    }

    private function createWaitlistEntry(Game $game, User $user): ParticipationResult
    {
        $player = GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'waitlist_at' => now(),
        ]);

        return new ParticipationResult(
            outcome: ParticipationOutcome::Waitlisted,
            waitlistPosition: $this->waitlistPosition($game, $player),
            target: $user,
        );
    }

    private function dropPlayer(Game $game, GamePlayer $gamePlayer): ParticipationResult
    {
        $wasWaitlisted = $gamePlayer->waitlist_at !== null;

        $gamePlayer->update(['dropped_out' => true]);

        if ($wasWaitlisted) {
            return new ParticipationResult(
                outcome: ParticipationOutcome::LeftWaitlist,
                target: $gamePlayer->user,
            );
        }

        $promoted = null;

        if (in_array($game->status, [GameStatus::OPEN, GameStatus::FULL], true)) {
            $promoted = $this->waitlistService->promoteFromWaitlistBeforeDraft($game);

            if (! $promoted && $game->status === GameStatus::FULL) {
                $game->update(['status' => GameStatus::OPEN]);
            }
        }

        if ($game->status === GameStatus::DRAFTED) {
            $promoted = $this->waitlistService->promoteFromWaitlist($game, $gamePlayer->user_id);
        }

        return new ParticipationResult(
            outcome: ParticipationOutcome::Quit,
            promoted: $promoted?->user,
            target: $gamePlayer->user,
        );
    }

    private function alreadyParticipating(Game $game, GamePlayer $existing): ParticipationResult
    {
        if ($existing->waitlist_at !== null) {
            return new ParticipationResult(
                outcome: ParticipationOutcome::AlreadyWaitlisted,
                waitlistPosition: $this->waitlistPosition($game, $existing),
                target: $existing->user,
            );
        }

        return new ParticipationResult(
            outcome: ParticipationOutcome::AlreadyJoined,
            target: $existing->user,
        );
    }

    private function findParticipation(Game $game, User $user): ?GamePlayer
    {
        return GamePlayer::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function findActiveParticipation(Game $game, User $user): ?GamePlayer
    {
        return GamePlayer::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->where('dropped_out', false)
            ->first();
    }

    private function waitlistPosition(Game $game, GamePlayer $player): int
    {
        return GamePlayer::where('game_id', $game->id)
            ->whereNotNull('waitlist_at')
            ->where('dropped_out', false)
            ->where('waitlist_at', '<=', $player->waitlist_at)
            ->count();
    }

    private function afterMainListChange(Game $game, ParticipationResult $result): void
    {
        if ($result->outcome !== ParticipationOutcome::Joined) {
            return;
        }

        $freshGame = Game::findOrFail($game->id);

        if ($freshGame->status === GameStatus::FULL) {
            $this->gameService->handleGameBecameFull($freshGame, $this->draftService);

            return;
        }

        $payload = GamePayload::fromGame($freshGame, $this->draftService);
        $this->broadcastPlayerListChanged($freshGame, $payload);
    }

    private function afterDrop(Game $game, User $user): void
    {
        $freshGame = Game::findOrFail($game->id);

        rescue(fn () => $this->paymentService->cancelPaymentForPlayer($freshGame->id, $user->id), report: false);

        $payload = GamePayload::fromGame($freshGame, $this->draftService);
        $this->broadcastPlayerListChanged($freshGame, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function broadcastPlayerListChanged(Game $game, array $payload): void
    {
        rescue(function () use ($game, $payload): void {
            broadcast(new GamePlayerJoined($game->id, $payload))->toOthers();
        }, report: false);
    }
}

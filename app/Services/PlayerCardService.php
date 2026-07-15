<?php

namespace App\Services;

use App\Enums\CardType;
use App\Models\Game;
use App\Models\PlayerCard;
use App\Models\PlayerCardCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlayerCardService
{
    public function currentRound(): int
    {
        return (int) (Game::max('round') ?? 0);
    }

    /**
     * @return array<int, array{type: string, round: int}>
     */
    public function getDisplayCards(User $user, ?int $currentRound = null): array
    {
        $currentRound ??= $this->currentRound();
        $cycle = $this->getVisibleCycle($user, $currentRound);

        if (! $cycle) {
            return [];
        }

        return $cycle->cards
            ->sortBy('round')
            ->values()
            ->map(fn (PlayerCard $card) => [
                'type' => $card->type->value,
                'round' => $card->round,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, type: string, round: int}>
     */
    public function getCardHistory(User $user): array
    {
        return PlayerCard::query()
            ->where('user_id', $user->id)
            ->orderBy('round')
            ->orderBy('id')
            ->get()
            ->map(fn (PlayerCard $card) => [
                'id' => $card->id,
                'type' => $card->type->value,
                'round' => $card->round,
            ])
            ->all();
    }

    public function addCard(User $user, CardType $type, int $round): PlayerCard
    {
        return DB::transaction(function () use ($user, $type, $round) {
            $cycle = $this->getOrCreateOpenCycle($user);

            $yellowCount = $cycle->cards()->where('type', CardType::YELLOW)->count();
            $redCount = $cycle->cards()->where('type', CardType::RED)->count();

            if ($type === CardType::YELLOW && $yellowCount >= 3) {
                throw ValidationException::withMessages([
                    'type' => 'O jogador já possui 3 cartões amarelos neste ciclo.',
                ]);
            }

            if ($type === CardType::YELLOW && $redCount >= 1) {
                throw ValidationException::withMessages([
                    'type' => 'O jogador já possui cartão vermelho neste ciclo.',
                ]);
            }

            if ($type === CardType::RED && $redCount >= 1) {
                throw ValidationException::withMessages([
                    'type' => 'O jogador já possui cartão vermelho neste ciclo.',
                ]);
            }

            $card = $cycle->cards()->create([
                'user_id' => $user->id,
                'type' => $type,
                'round' => $round,
            ]);

            $cycle->load('cards');
            $yellowCount = $cycle->cards->where('type', CardType::YELLOW)->count();
            $redCount = $cycle->cards->where('type', CardType::RED)->count();

            if ($yellowCount >= 3 || $redCount >= 1) {
                $this->applyPunishment($user, $cycle, $round);
            }

            return $card;
        });
    }

    private function applyPunishment(User $user, PlayerCardCycle $cycle, int $round): void
    {
        $suspendedUntil = $round + 2;

        $cycle->update([
            'infraction_round' => $round,
            'display_until_round' => $suspendedUntil,
        ]);

        if ($user->suspended_until_round === null || ($user->suspended_until_round !== 0 && $suspendedUntil > $user->suspended_until_round)) {
            $user->update(['suspended_until_round' => $suspendedUntil]);
        }
    }

    private function getOrCreateOpenCycle(User $user): PlayerCardCycle
    {
        $cycle = PlayerCardCycle::query()
            ->where('user_id', $user->id)
            ->whereNull('display_until_round')
            ->latest('id')
            ->first();

        if ($cycle) {
            $cycle->load('cards');

            return $cycle;
        }

        return PlayerCardCycle::create(['user_id' => $user->id]);
    }

    private function getVisibleCycle(User $user, int $currentRound): ?PlayerCardCycle
    {
        $displayingCycle = PlayerCardCycle::query()
            ->where('user_id', $user->id)
            ->whereNotNull('display_until_round')
            ->where('display_until_round', '>', $currentRound)
            ->with('cards')
            ->latest('id')
            ->first();

        if ($displayingCycle) {
            return $displayingCycle;
        }

        $openCycle = PlayerCardCycle::query()
            ->where('user_id', $user->id)
            ->whereNull('display_until_round')
            ->with('cards')
            ->latest('id')
            ->first();

        if ($openCycle && $openCycle->cards->isNotEmpty()) {
            return $openCycle;
        }

        return null;
    }
}

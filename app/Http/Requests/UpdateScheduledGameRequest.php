<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Services\GameService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScheduledGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'opens_at' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'opens_at' => 'abertura do mercado',
            'starts_at' => 'horário do jogo',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $opensAt = CarbonImmutable::parse($this->input('opens_at'), GameService::TZ);
            $startsAt = CarbonImmutable::parse($this->input('starts_at'), GameService::TZ);

            if ($startsAt->lte($opensAt)) {
                $validator->errors()->add('starts_at', 'O jogo deve ser depois da abertura do mercado.');
            }

            $game = $this->route('game');

            if ($game instanceof Game) {
                $dateTaken = Game::query()
                    ->whereDate('date', $startsAt->toDateString())
                    ->whereKeyNot($game->id)
                    ->exists();

                if ($dateTaken) {
                    $validator->errors()->add('starts_at', 'Já existe um jogo nesta data.');
                }
            }
        });
    }
}
